<?php

namespace lsolesen\pel;

class PelPngTextualCompressedChunk extends PelPngTextualChunk
{
    protected $shouldCompress = true;
    protected $compressAlgorithm = -1;

    public static function validateCompression($algorithmNumber)
    {
        if ($algorithmNumber !== 0) {
            throw new PelInvalidDataException(
                'Compression algorithm for %s was %i, which is unknown', __CLASS__, $algorithmNumber
            );
        }
    }

    public static function compress($data, $algorithm = 0)
    {
        static::validateCompression($algorithm);
        return substr(gzdeflate($data), 10);
    }


    public static function decompress($data, $algorithm = 0)
    {
        static::validateCompression($algorithm);
        //add gzip header
        /*$data = "\x1f"
            . "\x8B"
            . "\x08"
            ."\0\0\0\0\0\0\x03".$data;*/
        $ret = (new PelFlate())->decode($data);
        if ($ret === false) {
            throw new \Exception(sprintf("inflate failed on str: b'%s'\n", static::escapeStr($data)));
        } else {
            $retLen = strlen($ret);
            $retCutoff = 100;
        }
        return $ret;
    }

    public function getContentBytes()
    {
        throw new Exception("Function should never be called");
    }

    public function getTextValue()
    {
        return static::decompress($this->rawValue, $this->compressAlgorithm);
    }

    public function setTextValue($str)
    {
        $this->rawValue = static::compress($str, $this->compressAlgorithm);
    }

    public function fromValues(string $chunkType, string $keyword, string $plainValue, int $compressAlgorithm = 0)
    {
        $this->compressAlgorithm = $compressAlgorithm;
        parent::fromValues($chunkType, $keyword, $plainValue);
        return $this;
    }
}