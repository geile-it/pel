<?php

/**
 * Modified from https://github.com/Setasign/FPDI/blob/master/src/PdfParser/Filter/Flate.php ca. 22b3993670eeb232d55aefa3f82ee58e5a4dd22e
 * @copyright Copyright (c) 2023 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 *
 * The MIT License (MIT)
 * Copyright (c) 2023 Setasign GmbH & Co. KG, https://www.setasign.com
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
*/

namespace lsolesen\pel;


class PelFlateException extends \Exception
{
    /**
     * @var integer
     */
    const NO_ZLIB = 0x0401;

    /**
     * @var integer
     */
    const DECOMPRESS_ERROR = 0x0402;
}

class PelFlate
{
    /**
     * Checks whether the zlib extension is loaded.
     *
     * Used for testing purpose.
     *
     * @return boolean
     * @internal
     */
    protected function extensionLoaded()
    {
        return \extension_loaded('zlib');
    }

    /**
     * Decodes a flate compressed string.
     *
     * @param string|false $data The input string
     * @throws PelFlateException
     */
    public function decode($data)
    {
        if ($this->extensionLoaded()) {
            $oData = $data;
            $data = (($data !== '') ? @\gzuncompress($data) : '');
            if ($data === false) {
                // let's try if the checksum is CRC32
                $fh = fopen('php://temp', 'w+b');
                fwrite($fh, "\x1f\x8b\x08\x00\x00\x00\x00\x00" . $oData);
                // "window" == 31 -> 16 + (8 to 15): Uses the low 4 bits of the value as the window size logarithm.
                //                   The input must include a gzip header and trailer (via 16).
                stream_filter_append($fh, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
                fseek($fh, 0);
                $data = @stream_get_contents($fh);
                fclose($fh);
                if (is_array($data)) {
                    $_data = '';
                    foreach($data as $c) { $_data .= chr($c); }
                    $data = $_data;
                }

                if ($data) {
                    return $data;
                }

                // Try this fallback
                $tries = 0;

                $oDataLen = strlen($oData);
                while ($tries < 6 && ($data === false || (strlen($data) < ($oDataLen - $tries - 1)))) {
                    $data = @(gzinflate(substr($oData, $tries)));
                    $tries++;
                }
                if (is_array($data)) {
                    $_data = '';
                    foreach($data as $c) { $_data .= chr($c); }
                    $data = $_data;
                }
                // let's use this fallback only if the $data is longer than the original data
                if (strlen($data) > ($oDataLen - $tries - 1)) {
                    return $data;
                }

                if (!$data) {
                    throw new PelFlateException(
                        'Error while decompressing stream.',
                        PelFlateException::DECOMPRESS_ERROR
                    );
                }
            }
        } else {
            throw new PelFlateException(
                'To handle FlateDecode filter, enable zlib support in PHP.',
                PelFlateException::NO_ZLIB
            );
        }

        return $data;
    }
}