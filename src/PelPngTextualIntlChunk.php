<?php

namespace lsolesen\pel;

class PelPngTextualIntlChunk extends PelPngTextualCompressedChunk
{
    const header = PelPng::PNG_CHUNK_TEXTUAL_DATA_INTL;
    protected $language = null;
    protected $translatedKeyword = null;

    public function parseTextualHeaders(PelDataWindow $data)
    {
        parent::parseTextualHeaders($data);
        // compresison flag
        $compressEnable = $data->getByte();
        if ($compressEnable != 0 && $compressEnable != 1) {
            throw new PelInvalidDataException(
                "Invalid PNG compression flag in %s: %i",
                $this->type,
                $compressEnable
            );
        }
        $this->shouldCompress = $compressEnable;
        $data->setWindowStart(1);
        // compression method
        $compressAlgo = $data->getByte();
        $this->validateCompression($compressAlgo);
        $this->compressAlgorithm = $compressAlgo;
        $data->setWindowStart(1);
        // language for translation
        $lang = static::strncpy($data);
        $this->handlePadding($data);
        $this->language = $lang;
        // translated key
        $transKey = static::strncpy($data);
        $this->handlePadding($data);
        $this->translatedKeyword = $transKey;
        $payload = $data->getBytes();
        $this->rawValue = $payload;
    }

    public function getLanguage()
    {
        return $this->language;
    }

    public function setLanguage($lang)
    {
        $this->language = $lang;
    }

    public function getTranslatedKeyword()
    {
        return $this->translatedKeyword;
    }

    public function setTranslatedKeyword($keyword)
    {
        $this->translatedKeyword = $keyword;
    }

    public function getContentBytes()
    {
        return
            $this->keyword
            . static::NULL_TERMINATOR
            . pack('C', $this->shouldCompress)
            . pack('C', $this->compressAlgorithm)
            . $this->language . static::NULL_TERMINATOR
            . $this->translatedKeyword . static::NULL_TERMINATOR
            . $this->rawValue;
    }
}