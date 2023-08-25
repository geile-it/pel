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

class PelPngChunk {

    protected $type; // Four-byte chunk type
    protected $data; // Chunk data

    const CHUNK_PAD_WIDTH = 1;

    public function __construct(string $type = null, PelDataWindow $contents = null)
    {
        $this->type = $type;
        $this->data = $contents;
    }

    public static function int2hex(int $i) {
        return bin2hex(pack('N', $i));
    }

    /**
     * @throws PelInvalidDataException
     * @throws PelDataWindowWindowException
     * @throws PelDataWindowOffsetException
     */
    public function parseData(PelDataWindow $data, bool $checkCrc = true)
    {
        // PNG data is stored in big-endian format
        $data->setByteOrder(PelConvert::BIG_ENDIAN);
        $length = $data->getLong(0, 4);
        $data->setWindowStart(4);
        $type = $data->getBytes(0, 4);
        $data->setWindowStart(4);
        if ($length) {
            $chunkData = $data->getClone(0, $length);
        } else {
            $chunkData = new PelDataWindow('');
        }
        // seek off padding
        $padding_length = $length % self::CHUNK_PAD_WIDTH;
        $crc = $data->getBytes($length, 4);
        $data->setWindowStart($length + 4);
        $padding = '';
        if ($data->getSize() >= $padding_length) {
            Pel::debug("padding with $padding_length bytes: chunk $type");
            $padding = $data->getBytes(0, $padding_length);
            $data->setWindowStart($padding_length);
            if ($padding !== str_repeat("\0", $padding_length)) {
                throw new PelInvalidDataException("Received invalid padding (len $padding_length) for chunk '$type': '$padding'");
            }
            //$chunkData-> .= $padding;
        }
        $printCutoff = 100;

        // Verify CRC checksum
        $calculatedCrc = static::calculateCrc($type . $chunkData->getBytes());
        if ($calculatedCrc !== $crc) {
            if ($checkCrc) {
                throw new PelInvalidDataException(
                    "Invalid CRC checksum for chunk type %s (%s): calculated: %s (%s), on disk: %s (%s)",
                    //bin2hex(crc32($chunkData)),
                    bin2hex($type),
                    '',//$type,
                    bin2hex($calculatedCrc),
                    $calculatedCrc,
                    bin2hex($crc),
                    $crc
                );
            }
            Pel::warning("Hit incorrect CRC for chunk type %s", bin2hex($type));
        }

        // Create new PelChunk object
        $chunk = $this;
        $chunk->type = $type;
        $chunk->data = $chunkData;

        return $chunk;
    }

    public static function calculateCrc($data) {
        return pack('N', crc32($data));
    }

    private static function isUppercase($char) {
       return $char >= 'A' && $char <= 'Z';
    }


    public static function parseType($typeStr)
    {
        $type = unpack('N', $typeStr)[1];
        $name = '';

        for ($i = 0; $i < 4; $i++) {
            $charCode = ($type >> (($i * 8))) & 0xFF;
            $char = chr($charCode);
            $name .= $char;
        }

        $isCritical = self::isUppercase($name[0]);
        $isPublic = self::isUppercase($name[1]);
        $isReserved = self::isUppercase($name[2]);
        $isSafeToCopy = self::isUppercase($name[3]);

        return array(
            'name' => $name,
            'isCritical' => $isCritical,
            'isPublic' => $isPublic,
            'isReserved' => $isReserved,
            'isSafeToCopy' => $isSafeToCopy
        );
    }
    /**
     * Return the FourCC of the chunk, e.g., 'IHDR' for the start
     * of a PNG file.
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Return the size of the chunk
     * @return int
     */
    public function getContentSize()
    {
        return $this->data->getSize();
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
        $contentsize += $contentsize % self::CHUNK_PAD_WIDTH;
        // Header = Length(4) + Type(4) + CRC(4)
        return 12 + $contentsize;
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
        return $this->data->getBytes();
    }

    /**
     * Return the bytes of the chunk header.
     *
     * @return string bytes representing the chunk header.
     *         Two 32bit values: the chunk type string and the content length
     */
    public function getHeaderBytes()
    {
        return PelConvert::longToBytes($this->getContentSize(), PelConvert::BIG_ENDIAN) . $this->type;
    }

    /**
     * Return the bytes of the chunk, including the chunk header and potential padding byte.
     *
     * @return string bytes representing this chunk content. These bytes
     *         will match the bytes in a file, see also {@link parseData }.
     */
    public function getBytes()
    {
        $content = $this->getContentBytes();
        $length = strlen($content);
        $pad = $length % self::CHUNK_PAD_WIDTH;
        $name = $this->getType();
        if ($pad > 0) {
            Pel::debug("PNG chunk $name: Adding $pad padding bytes to $length");
            $content .= str_repeat("\0", $pad);
        }
        $crc = static::calculateCrc($name . $content);
        return $this->getHeaderBytes() . $content . $crc;
    }

    /**
     * Return the DataWindow of the chunk.
     *
     * @return PelDataWindow DataWindow representing the chunk contents.
     */
    public function getDataWindow()
    {
        return $this->data;
    }

    public function setDataWindow(PelDataWindow $dw)
    {
        $this->data = $dw;
    }

    public static function escapeStr($str): string
    {
        $res = '';
        foreach (str_split($str) as $char) {
            if (!ctype_print($char)) {
                $char = '\x' . str_pad(bin2hex($char), 2, '0');
            } elseif (is_int($char)) {
                $char = chr($char);
            }
            $res .= $char;
        }
        return $res;
    }

    public function __toString()
    {
        return Pel::fmt(
            "PNG Chunk: %s, content: %d, full length: %d",
            $this->type ?: "[none]",
            $this->getContentSize(),
            $this->getFullSize()
        );
    }
}
