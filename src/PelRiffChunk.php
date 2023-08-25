<?php

/*
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

/**
 * Classes for dealing with RIFF chunks.
 *
 * @author Jakob Berger <jakob@geile.it>
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public
 *          License (GPL)
 * @package PEL
 */
namespace lsolesen\pel;

class PelRiffChunk
{
    /**
     * The four-character chunk identifier
     * @var string
     */
    protected $fourCc = null;

    /**
     * The content stream
     * @var PelDataWindow
     */
    protected $content = null;

    /**
     * Make a new RIFF chunk.
     *
     * @param $fourCc string the chunk identifier
     * @param PelDataWindow $content the chunk content
     * @throws PelDataWindowWindowException
     */
    public function __construct($fourCc, PelDataWindow $content)
    {
        $this->fourCc = $fourCc;
        $this->content = $content->getClone();
    }

    /**
     * Make a new RIFF chunk from the data stream, parsing the RIFF header.
     *
     * @return PelRiffChunk
     * @throws PelException
     */
    public static function fromData(PelDataWindow $data)
    {
        $content = $data->getClone();
        if (!($content->getSize() > 7)) {
            throw new PelException("Data is too short: %d bytes", $content->getSize());
        }
        $fourCc = $data->getBytes(0, 4);
        $size = $data->getLong(4);
        $content->setWindowStart(8);
        // also validates the read chunk size
        $content->setWindowSize($size);
        return new PelRiffChunk($fourCc, $content);
    }

    /**
     * Return the FourCC of the chunk, e.g., 'RIFF' for the start
     * of a RIFF document.
     * @return string
     */
    public function getName()
    {
        return $this->fourCc;
    }

    /**
     * Return the size of the chunk
     * @return int
     */
    public function getContentSize()
    {
        return $this->content->getSize();
    }

    /**
     * Return the size of the full chunk, including the header and a
     * potential padding byte if the payload is of uneven length.
     *
     * Formula: Header=8; Header + ContentLength + (ContentLength % 2)
     *
     * @return int
     */
    public function getFullSize()
    {
        $contentsize = $this->getContentSize();
        // odd content sizes need to be padded with a single null byte to be even.
        $contentsize += $contentsize % 2;
        // header = 4 char bytes + one uint32 = 8 bytes
        return 8 + $contentsize;
    }

    /**
     * Return the bytes of the chunk content.
     *
     * @return string bytes representing this chunk content. These bytes
     *         will match the bytes given to {@link __construct the
     *         constructor}.
     */
    public function getContentBytes()
    {
        return $this->content->getBytes();
    }

    /**
     * Return the bytes of the chunk header.
     *
     * @return string bytes representing the chunk header.
     *         Two 32bit values: the 4CC string and the content length
     */
    public function getHeaderBytes()
    {
        return $this->fourCc . PelConvert::longToBytes($this->getContentSize(), PelConvert::LITTLE_ENDIAN);
    }

    /**
     * Return the bytes of the chunk, including the chunk header and potential padding byte.
     *
     * @return string bytes representing this chunk content. These bytes
     *         will match the bytes in a file, see also {@link fromData }.
     */
    public function getBytes()
    {
        $content = $this->getContentBytes();
        $length = strlen($content);
        // odd content sizes need to be padded with a single null byte to be even.
        if ($length % 2 > 0) {
            $name = $this->getName();
            Pel::debug("Riff chunk $name: Adding padding byte to $length");
            $content .= "\0";
        }
        return $this->getHeaderBytes() . $content;
    }

    /**
     * Return the DataWindow of the chunk.
     *
     * @return PelDataWindow bytes representing the chunk contents.
     */
    public function getDataWindow()
    {
        return $this->content;
    }

    public function setDataWindow(PelDataWindow $dw)
    {
        $this->content = $dw;
    }

    public function __toString()
    {
        return Pel::fmt(
            "RIFF Chunk: %s, content: %d, full length: %d",
            $this->fourCc ?: "[none]",
            $this->getContentSize(),
            $this->getFullSize()
        );
    }
}
