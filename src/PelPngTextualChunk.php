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

class PelPngTextualChunk extends PelPngChunk
{
    const header = PelPng::PNG_CHUNK_TEXTUAL_DATA;
    const NULL_TERMINATOR = "\0";
    protected $keyword;
    protected $rawValue;

    /**
     * Copies up to $iMax bytes from $data until a null byte is hit.
     * IMPORTANT: modifies the Window offset of $data!
     * @param PelDataWindow $data
     * @param int $iMax
     * @return string
     * @throws PelDataWindowOffsetException
     */
    protected static function strncpy(PelDataWindow $data, int $iMax = 0)
    {
        $copied = 0;
        $result = '';
        $current = -1;

        while ($data->getSize() && (!$iMax || $copied < $iMax)) {
            $current = $data->getByte(0);
            if ($current == 0) {
                break;
            }
            if ($data->getSize()) {
                $data->setWindowStart(1);
            }
            $copied++;
            $result .= chr($current);
        }
        return $result;
    }

    /***
     * Read $len amount of null-bytes from $data, advancing the read cursor.
     * Throws {@link PelInvalidDataException} on non-null bytes to expose invalid data and bugs.
     * Use plain old $data->setWindowStart() instead of this method if you need to ignore the bytes' contents.
     * @param PelDataWindow $data
     * @param $len int amount of null-bytes to read
     *
     * @return void
     * @throws PelInvalidDataException on non-null bytes
     * @throws PelDataWindowOffsetException
     * @throws PelDataWindowWindowException
     */
    public function handlePadding(PelDataWindow $data, int $len = 1, array $padding = ["\0"], $location = null)
    {
        for ($i=0; $i<$len; $i++) {
            $pad = $data->getByte();
            if (!in_array(chr($pad), $padding)) {
                throw new PelInvalidDataException(
                    "Textual Chunk %s: Didn't find expected padding byte%s, got '%s'",
                    static::escapeStr($this->type),
                    $location ? (" ".$location) : "",
                    static::escapeStr($pad)
                );
            }
            if ($data->getSize()) {
                $data->setWindowStart(1);
            }
        }
    }

    public function decodeValue(string $value_marker) {
        $hexenc = new PelDataWindow($this->getTextValue());
        $header_len = strlen($value_marker);
        $this->handlePadding($hexenc, 1, ["\n", " "], "before header '".static::escapeStr($value_marker)."'");
        $read_header = $hexenc->getBytes(0, $header_len);
        $hexenc->setWindowStart($header_len);
        $this->handlePadding($hexenc, 1, ["\n", " "], "after header '".static::escapeStr($value_marker)."'");
        if ($hexenc->getSize() < 1 || $read_header !== $value_marker) {
            throw new PelInvalidDataException(
                "Invalid hex-encoded Exif passed: Incorrect header: '%s', expected '%s'",
                PelPngTextualChunk::escapeStr($read_header),
                PelPngTextualChunk::escapeStr($value_marker)
            );
        }
        $raw_len = $hexenc->getBytes(0, 8);
        $len_parsed = intval($raw_len) * 2;
        if (!$len_parsed) {
            return null;
        }
        $hexenc->setWindowStart(8);
        $this->handlePadding($hexenc, 1, ["\n", " "], " after length description");
        //$hexenc->setWindowStart(1);
        $indexes = [];
        $lastPos = 0;
        while (($lastPos = strpos($hexenc->getBytes(), "\n", $lastPos))!== false) {
            $indexes[] = $lastPos;
            $lastPos++;
        }
        $hexenc =  new PelDataWindow(str_replace(["\n", " "], "", $hexenc->getBytes()));
        if ($hexenc->getSize() !== $len_parsed) {
            throw new PelInvalidDataException("Invalid size parsed: %s, but %s bytes left", $len_parsed, $hexenc->getSize());
        }
        $result = '';
        $i = 0;
        $raw = $hexenc->getBytes();
        while ($len_parsed > $i) {
            if ($raw[$i] == "\n") {
                $i++;
                continue;
            }
            if ($i+1 > $len_parsed) {
                throw new \Exception("Not enough bytes left (%s of %s)", $i, $len_parsed);
            }
            $result .= chr(intval(substr($raw, $i, 2), 16));
            $i += 2;
        }
        return $result;

    }

    public static function encodeValue(string $value_marker, string $value, $pad_interval = 0, $pad_symbol="\n") {
        $header = "\n$value_marker\n";
        $encoded = '';
        foreach (str_split($value_marker) as $c) {
            $encoded .= pack("C", $c);
        }
        $encodedLen = strlen($encoded) / 2;
        $header .= str_pad($encodedLen, 8, " ", STR_PAD_LEFT);
        // padding is not accounted for in length field
        if ($pad_interval) {
            $origLen = strlen($encoded);
            $padded = '';
            for($i = 0; $i < $origLen; $i++) {
                if ($i && $i % $pad_interval == 0 && $i < $origLen-1) {
                    $padded .= $pad_symbol;
                }
                $padded .= $encoded[$i];
            }
            $encoded = $padded;
        }

        return $header . "\n" . $encoded;
    }

    /**
     * @throws PelDataWindowOffsetException
     * @throws PelInvalidDataException
     */
    protected function parseTextualHeaders(PelDataWindow $data)
    {
        $data->setByteOrder(PelConvert::BIG_ENDIAN);
        $this->keyword = static::strncpy($data, 79); // moves the $data offset
        //$data->setWindowStart(strlen($this->keyword) + 1);
        $this->handlePadding($data);
        $this->rawValue = $data->getBytes();
    }

    public function parseData(PelDataWindow $data, bool $checkCrc = true)
    {
        parent::parseData($data->getClone(), $checkCrc);
        $this->parseTextualHeaders($this->data);
        //$this->data = $data->getClone();
    }

    public function getContentBytes()
    {
        return $this->keyword . static::NULL_TERMINATOR . $this->rawValue;
    }

    public function getContentSize()
    {
        return strlen($this->getContentBytes());
    }

    public function getFullSize()
    {
        $full = $this->getBytes();
        return strlen($full);
    }

    public function getKeyword()
    {
        return $this->keyword;
    }

    public function setKeyword($keyword)
    {
        $this->keyword = $keyword;
    }

    public function getTextValue()
    {
        return $this->rawValue;
    }

    public function setTextValue($str)
    {
        $this->rawValue = $str;
    }

    public function getHeaderBytes()
    {
        return PelConvert::longToBytes($this->getContentSize(), PelConvert::BIG_ENDIAN) . $this->type;
    }

    public function fromValues(string $chunkType, string $keyword, string $plainValue) {
        $this->type = $chunkType;
        $this->setKeyword($keyword);
        $this->setTextValue($plainValue);
        return $this;
    }
}

