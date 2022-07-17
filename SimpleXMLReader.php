<?php
/**
 * SimpleXMLReader - extends for XMLReader class features
 *
 * I just wanted to make an easy way to iterate over all the nodes
 * via foreach in an XML document and this is what I came up with.
 * 
 * Remember!!! The main class XMLReader can going only forward
 * that why you can't read previous elements! Organize your code wisely
 *
 * Copyright (C) 2022 Denik <https://denik.od.ua>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * @package		SimpleXMLReader
 * @author		Denik
 * @version 	0.1.0
 * @link		https://denik.od.ua/
 * @license		AGPL-3.0-or-later <https://spdx.org/licenses/AGPL-3.0-or-later>
 * @copyright	Copyright (c) 2022
 * @property XMLReader|string|null Class of cursor pointer | uri | XML source | null
 */
class SimpleXMLReader implements Iterator {

	/**
	 * @var XMLReader
	 */
	private $reader;
	private $readerVariables = array(
		'path'         => array(),
		'index'        => 0,
		'skipNextRead' => false,
		
		/**
		 * stores the result of the last XMLReader::read() operation.
		 * @var bool
		 */
		'lastRead'     => true
	);

	public $debug = false;

    /**
     * cache for expansion into SimpleXMLElement
     *
     * @var null|SimpleXMLElement
     * @see asSimpleXML
     */
    private $simpleXML;

	/**
	 * @var int
	 */
	private $stopDepth = 0;
	private $stopIteration = false;
	private $rewindLock = false;

	public function __construct($source = null, $encoding = null, $flags = 0)
	{
		if ($source instanceof XMLReader)
		{
			$this->reader = $source;
		}
		else
		{
			$this->reader = new XMLReader;
			if ($source && is_string($source))
			{
				// Check $source is file or url
				if (strlen($source) < 4096 && ! preg_match('/[\x00-\x1F\x80-\xFF]/', $source)
					&& (filter_var($source, FILTER_VALIDATE_URL)
						|| file_exists($source)
					)
				) {
					$this->reader->open($source, $encoding, $flags);
				}
				else
				{
					$this->reader->XML($source, $encoding, $flags);
				}
			}
		}

		// Create public variables
		foreach ($this->readerVariables as $key => $value)
		{
			if (isset($this->reader->$key)) continue;
			$this->reader->$key = $value;
		}
	}

	/**
	 * decorate method calls
	 *
	 * @param string $name
	 * @param array $args
	 *
	 * @return mixed
	 */
	public function __call($name, $args)
	{
		return call_user_func_array(array($this->reader, $name), $args);
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function __isset($name)
	{
		return isset($this->reader->$name);
	}

	/**
	 * decorate property get
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function __get($name)
	{
		return $this->reader->$name;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @return void
	 */
	public function __set($name, $value)
	{
		throw new BadMethodCallException('XMLReader properties are read-only: ' . $name);
	}

	/**
	 * String of element
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->readString();
	}

	/**
	 * Initialize iterator - find and read first element
	 *
	 * @return bool
	 */
	public function rewind()
	{
		if ($this->stopIteration) return false;
		
		// Onetime call for object
		if ($this->rewindLock) return false;
		$this->rewindLock = true;

		if ($this->reader->skipNextRead)
		{
			$this->reader->skipNextRead = false;
			
			if ($this->debug) echo "{{$this->stopDepth},rewind=skip}\n";
		}
		else
		{
			$this->readElement();

			if ($this->debug) echo "{{$this->stopDepth},rewind=element}\n";
		}

		if ( ! $this->valid())
		{
			$this->stopIteration = true;
			$this->reader->skipNextRead = $this->depth < $this->stopDepth;
		}

		return true;
	}

	/**
	 * Index of read iteration
	 *
	 * @return void
	 */
	public function key()
	{
		return $this->reader->index;
	}

	/**
	 * Returns object of current element
	 *
	 * @return object
	 */
	public function current()
	{
		$current = clone $this;
		$current->stopDepth = $current->depth + 1;
		$current->rewindLock = false;

		if ($this->debug) echo "{{$this->stopDepth},current}\n";

		return $current;
	}

	/**
	 * Move to next element
	 *
	 * @param null|string $name name of element or emply
	 * @return bool
	 */
	public function next($name = null)
	{
		if ($this->stopIteration) return false;

		// For while construction
		if ( ! $this->rewindLock)
		{
			return $this->rewind();
		}
		
		if ($this->reader->skipNextRead)
		{
			$this->reader->skipNextRead = false;

			// Skip next again if need
			if ( ! $this->valid())
			{
				$this->stopIteration = true;
				$this->reader->skipNextRead = $this->depth < $this->stopDepth;
			}

			if ($this->debug) echo "{{$this->stopDepth},next=skip}\n";

			return true;
		}
		if ($this->debug) echo "{{$this->stopDepth},next=next}\n";

		$b = $this->nextElement($name);
		while($this->depth > $this->stopDepth && $b) {
			$b = $this->nextElement($name);
		}

		$this->reader->skipNextRead = $this->depth < $this->stopDepth;
		
		return $this->valid();
	}

	/**
	 * Check current object have elements for iteration
	 *
	 * @return bool
	 */
	public function valid()
	{
		$valid = $this->reader->lastRead && $this->depth === $this->stopDepth;

		if ($this->debug) echo "{{$this->stopDepth},valid=".var_export($valid, true).",lastRead=".var_export($this->reader->lastRead, true)."}\n";

		return $valid;
	}

	/**
	 * Get current element attributes
	 *
	 * @return array
	 */
	public function getAttributes()
	{
		// Parse attributes
		$attributes = array();
		if ($this->reader->hasAttributes)
		{
			while ($this->reader->moveToNextAttribute())
			{
				$attributes[$this->reader->name] = $this->reader->value;
			}
		}

		$this->reader->moveToElement();

		return $attributes;
	}

	/**
	 * Get Reader object
	 *
	 * @return XMLReader
	 */
	public function getReader()
	{
		return $this->reader;
	}

	/**
	 * Current node type as text
	 *
	 * @return string
	 */
	public function nodeTypeText($type = null)
	{
		if ($type === null) $type = $this->nodeType;
		switch ($type) {
			case XMLReader::NONE:                   return 'NONE'; break;
			case XMLReader::ELEMENT:                return 'ELEMENT'; break;
			case XMLReader::ATTRIBUTE:              return 'ATTRIBUTE'; break;
			case XMLReader::TEXT:                   return 'TEXT'; break;
			case XMLReader::CDATA:                  return 'CDATA'; break;
			case XMLReader::ENTITY_REF:             return 'ENTITY_REF'; break;
			case XMLReader::ENTITY:                 return 'ENTITY'; break;
			case XMLReader::PI:                     return 'PI'; break;
			case XMLReader::COMMENT:                return 'COMMENT'; break;
			case XMLReader::DOC:                    return 'DOC'; break;
			case XMLReader::DOC_TYPE:               return 'DOC_TYPE'; break;
			case XMLReader::DOC_FRAGMENT:           return 'DOC_FRAGMENT'; break;
			case XMLReader::NOTATION:               return 'NOTATION'; break;
			case XMLReader::WHITESPACE:             return 'WHITESPACE'; break;
			case XMLReader::SIGNIFICANT_WHITESPACE: return 'SIGNIFICANT_WHITESPACE'; break;
			case XMLReader::END_ELEMENT:            return 'END_ELEMENT'; break;
			case XMLReader::END_ENTITY:             return 'END_ENTITY'; break;
			case XMLReader::XML_DECLARATION:        return 'XML_DECLARATION'; break;
		}
		return 'UNKNOWN';
	}

	/**
	 * Interface for XMLReader::read()
	 *
	 * @return bool
	 */
	public function read()
	{
		$this->reader->index++;
		$this->reader->lastRead = $this->reader->read();

		$this->setPath();

		return $this->reader->lastRead;
	}

	/**
	 * Read node by type
	 *
	 * @return bool
	 */
	public function readByNodeType($nodeType)
	{
		// Find by node type
		while ($this->read()) {
			if ($this->nodeType === $nodeType)
				return true;
		}

		return false;
	}

	/**
	 * Read element
	 *
	 * @return bool
	 */
	public function readElement()
	{
		// Find first next Element
		return $this->readByNodeType(XMLReader::ELEMENT);
	}

	/**
	 * Interface for XMLReader::next()
	 *
	 * @return bool
	 */
	public function nextNode($name = null)
	{
		$this->reader->index++;

		$this->reader->lastRead = $name !== null ? $this->reader->next($name) : $this->reader->next();

		$this->setPath();

		return $this->reader->lastRead;
	}

	public function nextByNodeType($nodeType, $name = null)
	{
		// Find by node type
		while ($this->nextNode($name)) {
			if ($this->nodeType === $nodeType)
				return true;
		}

		return false;
	}

	public function nextElement($name = null)
	{
		// Find next Element on this depth
		return $this->nextByNodeType(XMLReader::ELEMENT, $name);
	}

	private function setPath()
	{
		if ($this->nodeType === XMLReader::ELEMENT)
		{
			$this->reader->path[$this->depth] = $this->name;

			if (count($this->reader->path) !== $this->depth + 1) {
				$this->reader->path = array_slice($this->reader->path, 0, $this->depth + 1);
			}
		}
	}

	public function getPath($asArray = false)
	{
		if ($asArray) return $this->reader->path;
		return '/' . implode('/', $this->reader->path);
	}

	/**
	 * Decorated method
	 *
	 * @throws BadMethodCallException in case XMLReader can not expand the node
	 * @return string
	 */
	public function readOuterXml()
	{
		// Compatibility libxml 20620 (2.6.20) or later - LIBXML_VERSION  / LIBXML_DOTTED_VERSION
		if (method_exists($this->reader, 'readOuterXml')) {
			return $this->reader->readOuterXml();
		}

		if (0 === $this->reader->nodeType) {
			return '';
		}

		$doc = new DOMDocument();

		$doc->preserveWhiteSpace = false;
		$doc->formatOutput       = true;

		$node = $this->expand($doc);

		return $doc->saveXML($node);
	}

	/**
	 * XMLReader expand node and import it into a DOMNode with a DOMDocument
	 *
	 * This is for example useful for DOMDocument::saveXML() {@see readOuterXml}
	 * or getting a SimpleXMLElement out of it {@see getSimpleXMLElement}
	 *
	 * @param DOMNode $baseNode
	 * @throws BadMethodCallException
	 * @return DOMNode
	 */
	public function expand(DOMNode $baseNode = null)
	{
		if (null === $baseNode) {
			$baseNode = new DomDocument();
		}

		if ($baseNode instanceof DOMDocument) {
			$doc = $baseNode;
		} else {
			$doc = $baseNode->ownerDocument;
			if (null === $doc) {
				throw new InvalidArgumentException('BaseNode has no OwnerDocument.');
			}
		}

		if (false === $node = $this->reader->expand($baseNode)) {
			throw new BadMethodCallException('Unable to expand node.');
		}

		if ($node->ownerDocument !== $doc) {
			$node = $doc->importNode($node, true);
		}

		return $node;
	}

	/**
	 * Decorated method
	 *
	 * @throws BadMethodCallException
	 * @return string
	 */
	public function readString()
	{
		// Compatibility libxml 20620 (2.6.20) or later - LIBXML_VERSION  / LIBXML_DOTTED_VERSION
		if (method_exists($this->reader, 'readString')) {
			return trim($this->reader->readString());
		}

		if (0 === $this->reader->nodeType) {
			return '';
		}

		if (false === $node = $this->reader->expand()) {
			throw new BadMethodCallException('Unable to expand node.');
		}

		return trim($node->textContent);
	}

	/**
	 * SimpleXMLElement for XMLReader::ELEMENT
	 *
	 * @param string $className SimpleXMLElement class name of the simplexml element
	 * @return SimpleXMLElement|null in case the current node can not be converted into a SimpleXMLElement
	 * @since 0.1.0
	 */
	public function getSimpleXMLElement($className = null)
	{
		if (null === $this->simpleXML) {
			if ($this->reader->nodeType !== XMLReader::ELEMENT) {
				return null;
			}

			$this->simpleXML = simplexml_import_dom($this->expand(), $className);
		}

		if (is_string($className) && !($this->simpleXML instanceof $className)) {
			$this->simpleXML = simplexml_import_dom(dom_import_simplexml($this->simpleXML), $className);
		}

		return $this->simpleXML;
	}
}
