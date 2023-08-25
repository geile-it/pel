<?php

/**
 * PEL: PHP Exif Library.
 * A library with support for reading and
 * writing all Exif headers in JPEG and TIFF images using PHP.
 *
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

namespace lsolesen\pel;

class PelPng
{
    const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    const PNG_HEADER_HEXENCODED_EXIF = "exif";
    // Constants for PNG chunk types
    const PNG_CHUNK_IMAGE_HEADER = 'IHDR';
    const PNG_CHUNK_PALETTE = 'PLTE';
    const PNG_CHUNK_TRANSPARENCY = 'tRNS';
    const PNG_CHUNK_IMAGE_DATA = 'IDAT';
    const PNG_CHUNK_END = 'IEND';
    const PNG_CHUNK_PHYSICAL_DIMENSIONS = 'pHYs';
    const PNG_CHUNK_TEXTUAL_DATA = 'tEXt';
    const PNG_CHUNK_TEXTUAL_DATA_INTL = 'iTXt';
    const PNG_CHUNK_TEXTUAL_DATA_ZLIB = 'zTXt';
    const PNG_CHUNK_TIME = 'tIME';
    const PNG_CHUNK_BACKGROUND_COLOR = 'bKGD';
    const PNG_CHUNK_SIGNIFICANT_BITS = 'sBIT';
    const PNG_CHUNK_STANDARD_ALPHA_COLOR = 'sRGB';
    const PNG_CHUNK_CHROMATICITY = 'cHRM';
    const PNG_CHUNK_GAMMA = 'gAMA';
    const PNG_CHUNK_ICC_PROFILE = 'iCCP';
    const PNG_CHUNK_EXIF = 'eXIf';
    const PNG_CHUNK_XMP = 'tXMP';
    const PNG_KEYWORD_RAW_PROFILE_EXIF = 'Raw profile type exif';
    const PNG_CHUNK_EXIF_KEYWORDS = [
      self::PNG_KEYWORD_RAW_PROFILE_EXIF,
    ];

    const PNG_CHUNK_TEXTUALS = [
        self::PNG_CHUNK_TEXTUAL_DATA => PelPngTextualChunk::class,
        self::PNG_CHUNK_TEXTUAL_DATA_INTL => PelPngTextualIntlChunk::class,
        self::PNG_CHUNK_TEXTUAL_DATA_ZLIB => PelPngTextualZlibChunk::class,
        self::PNG_CHUNK_ICC_PROFILE => PelPngTextualZlibChunk::class,
    ];

    /**
     * @var array[PelPngChunk]
     */
    protected $sections = [];
    // Properties to store PNG metadata
    protected $width;
    protected $height;
    protected $bitDepth;
    protected $colorType;
    protected $compressionMethod;
    protected $filterMethod;
    protected $interlaceMethod;

    public function __construct($data = false, bool $checkCrc = true)
    {
        if ($data !== false) {
            if (is_string($data)) {
                Pel::debug('Initializing PelPng object from %s', $data);
                $this->loadFile($data, $checkCrc);
            } elseif ($data instanceof PelDataWindow) {
                Pel::debug('Initializing PelPng object from PelDataWindow.');
                $this->load($data, $checkCrc);
            } elseif ((is_resource($data) && get_resource_type($data) == 'gd') || (PHP_VERSION_ID >= 80000 && is_object($data) && $data instanceof \GDImage)) {
                Pel::debug('Initializing PelPng object from image resource.');
                $this->load(new PelDataWindow($data), $checkCrc);
            } else {
                throw new PelInvalidArgumentException('Bad type for $data: %s', gettype($data));
            }
        }
    }

    // Method to parse PNG metadata

    /**
     * @throws PelDataWindowWindowException
     * @throws PelDataWindowOffsetException
     * @throws PelInvalidDataException
     */
    public function parseMetadata(PelPngChunk $ihdr_chunk)
    {
        $type = $ihdr_chunk->getType();
        if ($type !== self::PNG_CHUNK_IMAGE_HEADER) {
            throw new PelInvalidDataException('Invalid PNG file: wrong chunk type for IHDR chunk: %s', $type);
        }
        $header = $ihdr_chunk->getDataWindow()->getClone();
        assert($header->getByteOrder() == PelConvert::BIG_ENDIAN);

        $this->width = $header->getLong();
        $this->height = $header->getLong();
        $this->bitDepth = $header->getByte();
        $this->colorType = $header->getByte();
        $this->compressionMethod = $header->getByte();
        $this->filterMethod = $header->getByte();
        $this->interlaceMethod = $header->getByte();
    }

    /**
     * Load data into a PNG object.
     *
     * The data supplied will be parsed and turned into an object
     * structure representing the image. This structure can then be
     * manipulated and later turned back into an string of bytes.
     *
     * @param PelDataWindow $data
     *            the data that will be turned into JPEG
     *            sections.
     * @return PelPng|void
     * @throws PelDataWindowOffsetException
     * @throws PelDataWindowWindowException
     * @throws PelInvalidDataException
     */
    public function load(PelDataWindow $data, bool $checkCrc = true) {
        $data = $data->getClone();
        // PNG data is stored in big-endian format
        $data->setByteOrder(PelConvert::BIG_ENDIAN);

        // Check for PNG signature
        $signature = $data->getBytes(0, strlen(self::PNG_SIGNATURE));
        if ($signature !== self::PNG_SIGNATURE) {
            throw new PelInvalidDataException("Invalid PNG file: wrong signature: $signature");
        }
        $data->setWindowStart(strlen(self::PNG_SIGNATURE));

        // Parse chunks
        $offset = 0;
        $total = $data->getSize();
        $first = true;
        while ($offset < $total) {
            $left = $total - $offset;
            // peek into chunk to get size
            $chunkSize = $data->getLong() + 12;
            if ($chunkSize > $left) {
                throw new PelInvalidDataException("Read chunk %s bigger than what's left: size: $chunkSize, data left: $left", bin2hex($data->getLong(4)));
            }
            //$data->setWindowStart(4);
            $chunk = new PelPngChunk();
            $chunkOrig = $chunk;
            $chunkOrigData = $data->getClone(0, $chunkSize);
            $chunk->parseData($chunkOrigData->getClone(), $checkCrc);
            $chunkType = $chunk->getType();
            if (array_key_exists($chunkType, self::PNG_CHUNK_TEXTUALS)){
                $cls = self::PNG_CHUNK_TEXTUALS[$chunkType];
                /** @var PelPngTextualChunk $chunk */
                $chunk = new $cls();
                $chunk->parseData($data->getClone(0, $chunkSize), $checkCrc);
            } elseif ($chunk->getFullSize() !== $chunkSize) {
                $origData = PelPngTextualChunk::escapeStr($chunkOrig->getBytes());
                $syntheticData = PelPngTextualChunk::escapeStr($chunk->getBytes()).N;
                throw new PelInvalidDataException("Chunk size $chunkSize and parsed chunk size %s for chunk type %s don't match!", $chunk->getFullSize(), $chunk->getType());
            }
            if (($oD = $chunkOrigData->getBytes()) !==  ($nD = $chunk->getBytes())) {
                throw new \Exception("Chunk output varies");
            }
            $this->sections[] = $chunk;
            //$chunkSize = $chunk->getFullSize();
            if ($chunkSize < $left) {
                $data->setWindowStart($chunkSize );
            }
            if ($first) {
                $this->parseMetadata($chunk);
                $first = false;
            }
            $offset += $chunkSize;
        }
        if ($offset !== $total) {
            throw new PelInvalidDataException("Leftover PNG data: %s bytes", $total - $offset );
        }
        return $this;
    }

    /**
     * Load data from a file into a PNG object.
     *
     * @param string $filename.
     *            This must be a readable file.
     * @return void
     * @throws PelException if file could not be loaded
     */
    public function loadFile($filename, bool $checkCrc = true)
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            throw new PelException('Can not open file "%s"', $filename);
        } else {
            $this->load(new PelDataWindow($content), $checkCrc);
        }
    }

    /**
     * Insert a new section.
     *
     * Please use @{link setExif()} instead if you intend to add Exif
     * information to an image as that function will know the right
     * place to insert the data.
     *
     * @param PelPngChunk $section
     *            the new section.
     * @param integer $offset
     *            the offset where the new section will be inserted ---
     *            use 0 to insert it at the very beginning, use 1 to insert it
     *            between sections 1 and 2, etc.
     */
    public function insertSectionAt(PelPngChunk $section, $offset)
    {
        array_splice($this->sections, $offset, 0, [$section]);
    }

    /**
     * Get a chunk corresponding to a particular chunk identifier.
     *
     * Please use the {@link getExif()} if you just need the Exif data.
     *
     * This will search through the chunks of this PNG object,
     * looking for chunks identified with the specified identifier.
     * The {@link PelPngChunk chunk} will then be returned.
     * The optional argument can be used to skip over
     * some of the sections. So if one is looking for the, say, third
     * EXIF section one would do:
     *
     * <code>
     * $exif3 = $WebP->getSection([CC4_EXIF], 2);
     * </code>
     *
     * @param ?array[string] $filters
     *            the chunk types to filter for
     * @param integer $skip
     *            the number of sections to be skipped. This must be a
     *            non-negative integer.
     * @return PelPngChunk|null the content found, or null if there is no
     *         content available.
     */
    public function getSection($filters = null, $skip = 0)
    {
        $res = $this->getSections($filters, $skip);
        if (empty($res)) {
            return null;
        }
        return $res[0];
    }

    /**
     * Get all sections.
     *
     * @return array[PelPngChunk] the found chunks
     */
    public function getSections($filters = null, $skip = 0)
    {
        $results = [];
        foreach ($this->sections as $item) {
            /** @var PelPngChunk $item */
            if ($skip > 0) {
                $skip--;
                continue;
            }
            if (!is_null($filters) && !in_array($item->getType(), $filters)) {
                continue;
            }
            $results[] = $item;
        }
        return $results;
    }

    /**
     * Get all sections.
     *
     * @return array[PelPngChunk] the found chunks
     */
    public function getSectionsTextual(array $keywords, $filters = null, $skip = 0)
    {
        $results = [];
        foreach ($this->sections as $item) {
            /** @var PelPngChunk $item */
            if ($skip > 0) {
                $skip--;
                continue;
            }
            if (!in_array($item->getType(), array_keys(self::PNG_CHUNK_TEXTUALS))) {
                // not a textual type
                continue;
            }
            if ((!is_null($filters)) && (!in_array($item->getType(), $filters))) {
                continue;
            }
            /** @var PelPngTextualChunk $item */
            if (!in_array($item->getKeyword(), $keywords)) {
                continue;
            }
            $results[] = $item;
        }
        return $results;
    }
    
    /**
     * Test data to see if it could be a valid PNG image.
     *
     * The function will only look at the first few bytes of the data,
     * and try to determine if it could be a valid PNG image based on
     * those bytes. This means that the check is more like a heuristic
     * than a rigorous check.
     *
     * @param PelDataWindow $d
     *            the bytes that will be checked.
     * @return boolean true if the bytes look like the beginning of a
     *         PNG image, false otherwise.
     * @see PelTiff::isValid()
     */
    public static function isValid(PelDataWindow $data)
    {
        $data->setByteOrder(PelConvert::BIG_ENDIAN);
        $signature = $data->getBytes(0, 8);
        return $signature == self::PNG_SIGNATURE;
    }

    public function getBytes()
    {
        $bytes = '';
        /** @var PelPngChunk $section */
        foreach($this->sections as $section) {
            $bytes .= $section->getBytes();
        }
        return self::PNG_SIGNATURE . $bytes;
    }

    public static function isTextualType(string $chunkType) {
        return in_array($chunkType, array_keys(self::PNG_CHUNK_TEXTUALS));
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
    public function setExif(PelExif $exif, string $chunkType = self::PNG_CHUNK_EXIF)
    {
        $this->clearExif();
        if (($tiff = $exif->getTiff()) != null) {
            if ($chunkType == self::PNG_CHUNK_EXIF) {
                $this->addSection($chunkType, $tiff->getBytes());
            } elseif (self::isTextualType($chunkType)) {
                throw new \Exception("Not implemented yet - doesn't work with $chunkType yet due to compression issues");
                $this->addSectionTextual($chunkType, self::PNG_KEYWORD_RAW_PROFILE_EXIF, PelPngTextualChunk::encodeValue(self::PNG_HEADER_HEXENCODED_EXIF, $exif->getBytes()));
            }
        } else {
            throw new \Exception("Tiff was null!");
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
        $this->addSection(self::PNG_CHUNK_ICC_PROFILE, $icc);
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
        $this->addSection(self::PNG_CHUNK_XMP, $xmp);
    }

    /**
     * @throws PelDataWindowWindowException
     * @throws PelInvalidDataException
     * @throws PelDataWindowOffsetException
     */

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
        if (!empty($sections = $this->getSections([self::PNG_CHUNK_EXIF]))) {
            /** @var PelPngChunk $exifSection */
            $exifSection = $sections[0];
            $data = new PelDataWindow(PelExif::EXIF_HEADER . $exifSection->getContentBytes());
        } elseif (!empty($sections = $this->getSectionsTextual(self::PNG_CHUNK_EXIF_KEYWORDS))) {
            // textual data
            /** @var PelPngTextualChunk $exifSection */
            $exifSection = $sections[0];
            $data = new PelDataWindow($exifSection->decodeValue(self::PNG_HEADER_HEXENCODED_EXIF));
        } else {
            return null;
        }
        $exif = new PelExif();
        $exif->load($data);
        return $exif;
    }

    /**
     * Get ICC data.
     *
     * Use this to get the stored @{link self::PNG_CHUNK_ICC_PROFILE ICC} data.
     *
     * @return PelPngChunk|null the ICC data found or null if the image has no
     *         ICC data.
     */
    public function getICC()
    {
        return $this->getSection([self::PNG_CHUNK_ICC_PROFILE]);
    }

    /**
     * Get XMP data.
     *
     * Use this to get the stored @{link self::CC4_XMP_SPEC XMP} data.
     *
     * @return PelPngChunk|null the XMP data found or null if the image has no
     *         XMP data.
     */
    public function getXMP()
    {
        return $this->getSection([self::PNG_CHUNK_XMP]);
    }

    public function removeSections(array $sectionTypes)
    {
        $sections = [];
        foreach ($this->getSections() as $section) {
            if (!in_array($section->getType(), $sectionTypes)) {
                $sections[] = $section;
            }
        }
        $this->sections = $sections;
    }

    public function removeSectionsByKeyword(array $keywords, array $sectionTypes = null)
    {
        $sections = [];
        $sectionTypes = $sectionTypes ?: array_keys(self::PNG_CHUNK_TEXTUALS);
        foreach ($this->getSections() as $section) {
            if (in_array($section->getType(), $sectionTypes)) {
                /** @var PelPngTextualChunk $section */
                if (in_array($section->getKeyword(), $keywords)) {
                    continue;
                }
            }
            $sections[] = $section;
        }
        $this->sections = $sections;
    }

    /**
     * Clear any Exif data.
     *
     * This method will only clear @{link self::PNG_CHUNK_EXIF} sections found.
     */
    public function clearExif()
    {
        $this->removeSections([self::PNG_CHUNK_EXIF]);
        $this->removeSectionsByKeyword(self::PNG_CHUNK_EXIF_KEYWORDS);
    }

    /**
     * Clear any XMP data.
     *
     * This method will clear any @{link self::CC4_XMP_ALTERNATIVES} sections found.
     */
    public function clearXMP()
    {
        $this->removeSections([self::PNG_CHUNK_XMP]);
    }

    /**
     * Clear any ICC data.
     *
     * This method will only clear @{link self::PNG_CHUNK_ICC_PROFILE} sections found.
     */
    public function clearICC()
    {
        $this->removeSections([self::PNG_CHUNK_ICC_PROFILE]);
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
        $chunk = new PelPngChunk($sectionType, new PelDataWindow($content));
        $this->insertSectionAt($chunk, $this->getInsertPosition($sectionType));
    }

    public function addSectionTextual(string $sectionType, string $keyword, string $content)
    {
        if (!in_array($sectionType, array_keys(self::PNG_CHUNK_TEXTUALS))) {
            throw new PelInvalidDataException("Section type %s is not a textual section type", PelPngTextualChunk::escapeStr($sectionType));
        }
        $cls = self::PNG_CHUNK_TEXTUALS[$sectionType];
        $chunk = new $cls();
        $chunk->fromValues($sectionType, $keyword, $content);
        $this->insertSectionAt($chunk, $this->getInsertPosition($sectionType));
    }

    public function getInsertPosition(string $sectionType, int $minOffset=0)
    {
        // TODO: properly recommend a chunk position based on chunk type and $minOffset
        $position = 1; // firmly after IHDR and before IDAT
        return max($position, $minOffset);
    }

    /**
     * Make a string representation of this PNG object.
     *
     * This is mainly useful for debugging. It will show the structure
     * of the image, and its sections.
     *
     * @return string debugging information about this PNG object.
     */
    public function __toString()
    {
        $res = "[";
        $elements = [];
        Pel::debug(Pel::tra("Dumping PNG data...\n"));
        foreach($this->sections as $section) {
            $elements[] =  $section->__toString();
        }
        return "[" . implode(", ", $elements) . "]";
    }
}
