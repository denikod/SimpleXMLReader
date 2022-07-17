## Iterators for [PHP `XMLReader`] for Ease of Parsing

[PHP `XMLReader`]: https://www.php.net/XMLReader

### Change Log:

 - `0.1.0` Initial release.

### Example
   ~~~php
	$reader = new SimpleXMLReader("simple.xml");
	// or:
	// $reader = new SimpleXMLReader(file_get_contents("simple.xml"));

	foreach($reader as $element) {
		echo str_repeat("\t", $element->depth), $element->name,  " (", $element->getPath(), ")\n";

		foreach($element as $childs) {
			echo str_repeat("\t", $childs->depth), $childs->name,  " (", $childs->getPath(), ")\n";
		}
	}
   ~~~
