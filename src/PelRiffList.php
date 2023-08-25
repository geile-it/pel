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
 * Classes for dealing with RIFF lists.
 *
 * A RIFF list is a RIFF chunk that contains one or more RIFF chunks as its payload.
 *
 * @author Jakob Berger <jakob@geile.it>
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public
 *          License (GPL)
 * @package PEL
 */
namespace lsolesen\pel;

class PelRiffList extends PelRiffChunk
{
    const CC4_RIFF = "RIFF";
    const CC4_LIST = "LIST";
    const DEFAULT_LIST_CC4S = [PelRiffList::CC4_RIFF, PelRiffList::CC4_LIST];

    /**
     * The child-items; can be either chunks or lists
     * @var array[PelRiffChunk]
     */
    protected $items = [];

    /**
     * Make a new RIFF list.
     *
     * @param PelRiffChunk $chunk the list data including the header
     * @param array[string] $listTypes the 4CCs names of chunks that are lists too
     * @throws PelDataWindowWindowException|PelException
     */
    public function __construct(PelRiffChunk $chunk, $listTypes = PelRiffList::DEFAULT_LIST_CC4S)
    {
        parent::__construct($chunk->fourCc, $chunk->content);
        $reader = $chunk->content->getClone();
        while ($reader->getSize() > 7) {
            $subChunk = PelRiffChunk::fromData($reader);
            $reader->setWindowStart($subChunk->getFullSize());
            if (in_array($subChunk->getName(), $listTypes)) {
                $sublist = new PelRiffList($subChunk, $listTypes);
                $this->items[] = $sublist;
            } else {
                $this->items[] = $subChunk;
            }
        }
        if ($reader->getSize() != 0) {
            Pel::debug("RiffChunk: leftover data detected, hopefully padding: %d bytes", $reader->getSize());
        }
    }

    /**
     * Make a new RIFF list from a data buffer.
     *
     * @param PelDataWindow $data the list data including the header
     * @param array[string] $listTypes the 4CCs names of chunks that are lists too
     *
     * @return PelRiffList
     *
     * @throws PelException
     */
    public static function fromData($data, $listTypes = PelRiffList::DEFAULT_LIST_CC4S)
    {
        return new PelRiffList(PelRiffChunk::fromData($data), $listTypes);
    }

    /**
    * Get the RIFF chunks contained in this list
    *
    * @param ?array[string] $filter Optional filters for items by their 4CC identifiers
    * @param int $skip Skip `skip` number of items
    *
    * @return array[PelRiffChunk]
    **/
    public function getItems($filters = null, $skip = 0)
    {
        $results = [];
        foreach ($this->items as $item) {
            if ($skip > 0) {
                $skip--;
                continue;
            }
            if (!is_null($filters) && !in_array($item->getName(), $filters)) {
                continue;
            }
            $results[] = $item;
        }
        return $results;
    }

    /**
     * Get the size of the list contents, without the list header
     * @return int
     */
    public function getContentSize()
    {
        $result = 0;
        foreach ($this->items as $item) {
            $result += $item->getFullSize();
        }
        return $result;
    }

    public function insertItem(PelRiffChunk $item, $offset)
    {
        array_splice($this->items, $offset, 0, [$item]);
    }

    public function setItems(array $items)
    {
        $this->items = $items;
    }

    /**
     * Return the bytes of the list chunk content, recursively getting the sub-chunks' contents again
     *
     * @return string bytes representing this chunk content. These bytes
     *         will match the bytes given to {@link __construct the
     *         constructor}.
     */
    public function getContentBytes()
    {
        $result = "";
        foreach ($this->items as $item) {
            $result .= $item->getBytes();
        }
        return $result;
    }

    public function __toString()
    {
        $members = [Pel::fmt("RIFF List: %s length: %d", $this->fourCc, $this->getFullSize())];
        foreach ($this->items as $item) {
            foreach (explode("\n", $item->__toString()) as $line) {
                $members[] = "  " . $line;
            }
        }
        return implode("\n", $members);
    }
}
