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

class PelPngTextualZlibChunk extends PelPngTextualCompressedChunk
{
    const header = PelPng::PNG_CHUNK_TEXTUAL_DATA_ZLIB;

    public function parseTextualHeaders(PelDataWindow $data)
    {
        $data->setByteOrder(PelConvert::BIG_ENDIAN);
        parent::parseTextualHeaders($data);
        $compressAlgo = $data->getByte();
        $this->validateCompression($compressAlgo);
        $data->setWindowStart(1);
        $this->compressAlgorithm = $compressAlgo;
        $payload = $data->getBytes();
        $this->rawValue = $payload;
    }

    public function getContentBytes()
    {
        return
            $this->keyword
            . static::NULL_TERMINATOR
            . pack('C', $this->compressAlgorithm)
            . $this->rawValue;
    }
}