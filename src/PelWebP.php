<?php

/**
 * PEL: PHP Exif Library.
 * A library with support for reading and
 * writing all Exif headers in WebP and TIFF images using PHP.
 *
 * Copyright (C) 2004, 2005, 2006, 2007 Martin Geisler.
 * Copyright (C) 2023 Jakob Berger.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program in the file COPYING; if not, write to the
 * Free Software Foundation, Inc., 51 Franklin St, Fifth Floor,
 * Boston, MA 02110-1301 USA
 */

/**
 * Class for handling WebP data.
 *
 * The {@link PelWebP} class defined here provides an abstraction for
 * dealing with a WebP file. The file will be contain a number of
 * sections containing some {@link PelWebPContent content} identified
 * by a {@link PelWebPMarker marker}.
 *
 * The {@link getExif()} method is used get hold of the {@link
 * PelWebPMarker::APP1 APP1} section which stores Exif data. So if
 * the name of the WebP file is stored in $filename, then one would
 * get hold of the Exif data by saying:
 *
 * <code>
 * $WebP = new PelWebP($filename);
 * $exif = $WebP->getExif();
 * $tiff = $exif->getTiff();
 * $ifd0 = $tiff->getIfd();
 * $exif = $ifd0->getSubIfd(PelIfd::EXIF);
 * $ifd1 = $ifd0->getNextIfd();
 * </code>
 *
 * The $idf0 and $ifd1 variables will then be two {@link PelTiff TIFF}
 * {@link PelIfd Image File Directories}, in which the data is stored
 * under the keys found in {@link PelTag}.
 *
 * Should one have some image data (in the form of a {@link
 * PelDataWindow}) of an unknown type, then the {@link
 * PelWebP::isValid()} function is handy: it will quickly test if the
 * data could be valid WebP data. The {@link PelTiff::isValid()}
 * function does the same for TIFF images.
 *
 * @author Martin Geisler <mgeisler@users.sourceforge.net>
 * @author Jakob Berger <jakob@geile.it>
 * @package PEL
 */
namespace lsolesen\pel;

class PelWebP
{

    const CC4_WEBP = "WEBP";
    const CC4_EXIF = "EXIF";
    const CC4_ICC = "ICCP";
    const CC4_XMP_SPEC = "XMP "; // yes, that's a space. As dictated by the WebP spec
    const CC4_XMP_0 = "XMP\0"; // alternate not-to-spec padding observed in the wild. sigh
    const CC4_VP8 = "VP8 ";
    const CC4_VP8L = "VP8L";

    const CC4_XMP_ALTERNATIVES = [PelWebP::CC4_XMP_SPEC, PelWebP::CC4_XMP_0];

    const IMAGE_DATA_CC4S = [PelWebP::CC4_VP8, PelWebP::CC4_VP8L];

    const ORDER_CC4S = [
        "VP8X", // extended info
        PelWebP::CC4_ICC,
        "ANIM", // animation info
        "ANMF", // animation frames
        "ALPH", // alpha channel info
        ...PelWebP::IMAGE_DATA_CC4S, // the actual image data
        PelWebP::CC4_EXIF, // exif data
        ...PelWebP::CC4_XMP_ALTERNATIVES, // xmp data
    ];


    /**
     * The sections in the WebP data.
     *
     * A WebP file is built up as a list of chunks/sublists, each
     * {@link PelRiffChunk} is identified with a 4 character name (CC4).
     *
     * The content can be either {@link PelRiffChunk RIFF chunks
     * or {@link PelRiffList RIFF lists}.
     *
     * @var PelRiffList
     */
    protected $sections = null;

    /**
     * The WebP image data.
     *
     * @var PelDataWindow
     */
    private $image_data = null;

    /**
     * Construct a new WebP object.
     *
     * The new object will be empty unless an argument is given from
     * which it can initialize itself. This can either be the filename
     * of a WebP image, a {@link PelDataWindow} object or a PHP image
     * resource handle.
     *
     * New Exif data (in the form of a {@link PelExif} object) can be
     * inserted with the {@link setExif()} method:
     *
     * <code>
     * $WebP = new PelWebP($data);
     * // Create container for the Exif information:
     * $exif = new PelExif();
     * // Now Add a PelTiff object with a PelIfd object with one or more
     * // PelEntry objects to $exif... Finally add $exif to $WebP:
     * $WebP->setExif($exif);
     * </code>
     *
     * @param boolean|string|PelDataWindow|resource|\GDImage $data
     *            the data that this WebP. This can either be a
     *            filename, a {@link PelDataWindow} object, or a PHP image resource
     *            handle.
     * @throws PelInvalidArgumentException
     */
    public function __construct($data = false)
    {
        if ($data !== false) {
            if (is_string($data)) {
                Pel::debug('Initializing PelWebP object from %s', $data);
                $this->loadFile($data);
            } elseif ($data instanceof PelDataWindow) {
                Pel::debug('Initializing PelWebP object from PelDataWindow.');
                $this->load($data);
            } elseif ((is_resource($data) && get_resource_type($data) == 'gd') || (PHP_VERSION_ID >= 80000 && is_object($data) && $data instanceof \GDImage)) {
                Pel::debug('Initializing PelWebP object from image resource.');
                $this->load(new PelDataWindow($data));
            } else {
                throw new PelInvalidArgumentException('Bad type for $data: %s', gettype($data));
            }
        }
    }

    /**
     * Load data into a WebP object.
     *
     * The data supplied will be parsed and turned into an object
     * structure representing the image. This structure can then be
     * manipulated and later turned back into an string of bytes.
     *
     * @param PelDataWindow $d
     *            the data that will be turned into WebP
     *            sections.
     */
    public function load(PelDataWindow $d)
    {
        Pel::debug('Parsing %d bytes...', $d->getSize());

        if (!PelWebP::isValid($d)) {
            throw new PelInvalidDataException("Data does not look like a WebP stream");
        }

        /* WebP data is stored in little-endian format. */
        $d->setByteOrder(PelConvert::LITTLE_ENDIAN);

        $riffRaw = PelRiffChunk::fromData($d);
        $d = $riffRaw->getDataWindow();
        // WEBP starts with an extra CC4 that is not its own chunk
        // advance the reader in the chunk
        $d->setWindowStart(4);
        $riff = new PelRiffList($riffRaw);
        Pel::debug("Loaded WebP file:\n" . $riff->__tostring());

        $riffLen = count($riff->getItems());

        if ($riffLen === 0) {
            throw new PelException("No RIFF subsections found!");
        }

        $this->sections = $riff;
        foreach ($this->sections->getItems() as $section) {
            $name = $section->getName();
            Pel::debug('Found %s section of length %d', $name, $section->getFullSize());
            if (in_array($name, PelWebP::IMAGE_DATA_CC4S)) {
                $this->image_data = $section->getDataWindow();
            }
        }
    }

    /**
     * Load data from a file into a WebP object.
     *
     * @param string $filename.
     *            This must be a readable file.
     * @return void
     * @throws PelException if file could not be loaded
     */
    public function loadFile($filename)
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            throw new PelException('Can not open file "%s"', $filename);
        } else {
            $this->load(new PelDataWindow($content));
        }
    }

    /**
     * Set Exif data.
     *
     * Use this to set the Exif data in the image. This will overwrite
     * any old Exif information in the image.
     *
     * @param PelExif $exif
     *            the Exif data.
     */
    public function setExif(PelExif $exif)
    {
        $this->clearExif();
        if ($exif->getTiff() != null) {
            $this->addSection(PelWebP::CC4_EXIF, $exif->getBytes());
        }
    }

    /**
     * Set ICC data.
     *
     * Use this to set the ICC data in the image. This will overwrite
     * any old ICC information in the image.
     *
     * @param string $icc
     *            the ICC data.
     */
    public function setICC(string $icc)
    {
        $this->clearICC();
        $this->addSection(PelWebP::CC4_ICC, $icc);
    }

    /**
     * Set XMP data.
     *
     * Use this to set the XMP data in the image. This will overwrite
     * any old XMP information in the image.
     *
     * @param string $xmp
     *            the XMP data.
     */
    public function setXMP(string $xmp)
    {
        $this->clearXMP();
        $this->addSection(PelWebP::CC4_XMP_SPEC, $xmp);
    }

    /**
     * Get first valid Exif section data.
     *
     * Use this to get the stored @{link PelExif Exif data}.
     *
     * @return PelExif|null the Exif data found or null if the image has no
     *         Exif data.
     */
    public function getExif()
    {
        $sections = $this->getSections([PelWebP::CC4_EXIF]);
        if (empty($sections)) {
            return null;
        }
        $exif = new PelExif();
        $exif->load($sections[0]->getDataWindow()->getClone());
        return $exif;
    }

    /**
     * Get ICC data.
     *
     * Use this to get the stored @{link PelWebP::CC4_ICC ICC} data.
     *
     * @return PelRiffChunk|null the ICC data found or null if the image has no
     *         ICC data.
     */
    public function getICC()
    {
        return $this->getSection([PelWebP::CC4_ICC]);
    }

    /**
     * Get XMP data.
     *
     * Use this to get the stored @{link PelWebP::CC4_XMP_SPEC XMP} data.
     *
     * @return PelRiffChunk|null the XMP data found or null if the image has no
     *         XMP data.
     */
    public function getXMP()
    {
        return $this->getSection(PelWebP::CC4_XMP_ALTERNATIVES);
    }

    public function removeSections(string $sectionType)
    {
        $sections = [];
        foreach ($this->getSections() as $section) {
            if ($section->getName() !== $sectionType) {
                $sections[] = $section;
            }
        }
        $this->sections->setItems($sections);
    }

    /**
     * Clear any Exif data.
     *
     * This method will only clear @{link PelWebP::CC4_EXIF} sections found.
     */
    public function clearExif()
    {
        $this->removeSections(PelWebP::CC4_EXIF);
        return;
    }

    /**
     * Clear any XMP data.
     *
     * This method will clear any @{link PelWebP::CC4_XMP_ALTERNATIVES} sections found.
     */
    public function clearXMP()
    {
        foreach (PelWebP::CC4_XMP_ALTERNATIVES as $xmp) {
            $this->removeSections($xmp);
        }
    }

    /**
     * Clear any ICC data.
     *
     * This method will only clear @{link PelWebP::CC4_ICC} sections found.
     */
    public function clearICC()
    {
        $this->removeSections(PelWebP::CC4_ICC);
    }

    /**
     * Append a new section.
     *
     * @param string $sectionType
     *            the 4CC identifying the new section.
     * @param string $content
     *            the content of the new section.
     */
    public function addSection(string $sectionType, string $content)
    {
        $chunk = new PelRiffChunk($sectionType, new PelDataWindow($content));
        $this->insertSectionAt($chunk, $this->getInsertPosition($sectionType));
    }

    /**
     * Get the RiffList position at which a Chunk of $type
     * would be inserted.
     *
     * @param string $chunkType
     * @return int
     * @throws \Exception
     */
    private function getInsertPosition(string $chunkType)
    {
        $scanned_until = 0;
        $order_index = -1;
        foreach (PelWebP::ORDER_CC4S as $_index => $order_item) {
            if ($order_item === $chunkType) {
                $order_index = $_index;
            }
        }
        assert($order_index != -1);
        $previousOrder = null;
        if ($order_index > 0) {
            $previousOrder = PelWebP::ORDER_CC4S[$order_index - 1];
        }
        $types = array_reverse(array_slice(PelWebP::ORDER_CC4S, $order_index));
        $sections_reverse = array_reverse($this->getSections());
        foreach ($types as $order_item) {
            $found = false;
            /** @var PelRiffChunk $section */
            foreach (array_slice($sections_reverse, $scanned_until) as $offset => $section) {
                $sectionType = $section->getName();
                if (!in_array($sectionType, PelWebP::ORDER_CC4S)) {
                    Pel::warning("WEBP ORDERING: Unknown WebP Section $sectionType, skipping");
                    continue;
                }
                if (!is_null($previousOrder) && $previousOrder === $sectionType) {
                    Pel::debug("We've arrived at the item that is before the one we expected...");
                    return count($sections_reverse) - ($scanned_until + $offset);
                }
                if ($sectionType === $order_item) {
                    $scanned_until += $offset + 1;
                    $found = true;
                    break;
                }
            }
            if (!$found && $order_item === $chunkType) {
                Pel::debug("We've arrived at $order_item");
                // we've exhausted all options before this, so we're at the right spot
                return count($sections_reverse) - $scanned_until;
            }
        }
        throw new \Exception("Logic error!");
    }

    /**
     * Insert a new section.
     *
     * Please use @{link setExif()} instead if you intend to add Exif
     * information to an image as that function will know the right
     * place to insert the data.
     *
     * @param PelRiffChunk $section
     *            the new section.
     * @param integer $offset
     *            the offset where the new section will be inserted ---
     *            use 0 to insert it at the very beginning, use 1 to insert it
     *            between sections 1 and 2, etc.
     */
    public function insertSectionAt(PelRiffChunk $section, $offset)
    {
        $this->sections->insertItem($section, $offset);
    }

    /**
     * Get a chunk corresponding to a particular chunk identifier.
     *
     * Please use the {@link getExif()} if you just need the Exif data.
     *
     * This will search through the chunks of this WebP object,
     * looking for chunks identified with the specified identifier.
     * The {@link PelRiffChunk chunk} will then be returned.
     * The optional argument can be used to skip over
     * some of the sections. So if one is looking for the, say, third
     * EXIF section one would do:
     *
     * <code>
     * $exif3 = $WebP->getSection([CC4_EXIF], 2);
     * </code>
     *
     * @param ?array[string] $filters
     *            the 4CC types to filter for
     * @param integer $skip
     *            the number of sections to be skipped. This must be a
     *            non-negative integer.
     * @return PelRiffChunk|null the content found, or null if there is no
     *         content available.
     */
    public function getSection($filters = null, $skip = 0)
    {
        $res = $this->sections->getItems($filters, $skip);
        if (empty($res)) {
            return null;
        }
        return $res[0];
    }

    /**
     * Get all sections.
     *
     * @return array[PelRiffChunk] the found chunks
     */
    public function getSections($filters = null)
    {
        return $this->sections->getItems($filters);
    }

    /**
     * Turn this WebP object into bytes.
     *
     * The bytes returned by this method is ready to be stored in a file
     * as a valid WebP image. Use the {@link saveFile()} convenience
     * method to do this.
     *
     * @return string bytes representing this WebP object, including all
     *         its sections and their associated data.
     */
    public function getBytes()
    {
        $headerSize = $this->sections->getContentSize() + 4; // content size plus WEBP header suffix
        $header = PelRiffList::CC4_RIFF; // RIFF type
        $header .= PelConvert::longToBytes($headerSize, PelConvert::LITTLE_ENDIAN);
        $header .= PelWebP::CC4_WEBP; // WEBP header suffix (not a new chunk)
        $content = $this->sections->getContentBytes();
        if (strlen($content) % 2 > 0) {
            $content .= "\0";
        }
        return $header . $content;
    }

    /**
     * Save the WebP object as a WebP image in a file.
     *
     * @param string $filename
     *            the filename to save in. An existing file with the
     *            same name will be overwritten!
     * @return integer|FALSE The number of bytes that were written to the
     *         file, or FALSE on failure.
     */
    public function saveFile($filename)
    {
        return file_put_contents($filename, $this->getBytes());
    }

    /**
     * Make a string representation of this WebP object.
     *
     * This is mainly usefull for debugging. It will show the structure
     * of the image, and its sections.
     *
     * @return string debugging information about this WebP object.
     */
    public function __toString()
    {
        Pel::debug(Pel::tra("Dumping WebP data...\n"));
        return $this->sections->__toString();
    }


    /**
     * Test data to see if it could be a valid WebP image.
     *
     * The function will only look at the first few bytes of the data,
     * and try to determine if it could be a valid WebP image based on
     * those bytes. This means that the check is more like a heuristic
     * than a rigorous check.
     *
     * @param PelDataWindow $d
     *            the bytes that will be checked.
     * @return boolean true if the bytes look like the beginning of a
     *         WebP image, false otherwise.
     * @see PelTiff::isValid()
     */
    public static function isValid(PelDataWindow $d)
    {
        /* WebP data is stored in big-endian format. */
        $d->setByteOrder(PelConvert::LITTLE_ENDIAN);
        // read out initial header
        if ($d->getBytes(0, 4) !== PelRiffList::CC4_RIFF) {
            Pel::warning("Stream lacks RIFF indicator (CC4)");
            return false;
        }
        // read out second 4CC, skipping the first one and size uint32
        if ($d->getBytes(8, 4) !== PelWebP::CC4_WEBP) {
            Pel::warning("Stream lacks WEBP indicator (CC4)");
            return false;
        }
        return true;
    }
}
