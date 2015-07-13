<?php
/**
 * Class for reading in NBT-format files.
 *
 * @author Justin Martin <frozenfire@thefrozenfire.com>
 * @author Fernando Marquardt <fernando.marquardt@gmail.com>
 * @version 1.0.0
 *
 * Dependencies:
 *  PHP 5.4+
 *  On x32 systems: GMP or bcmath extensions
 */

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class NBT {
	public $root = [];

	private $logger;

	const TAG_END = 0;
	const TAG_BYTE = 1;
	const TAG_SHORT = 2;
	const TAG_INT = 3;
	const TAG_LONG = 4;
	const TAG_FLOAT = 5;
	const TAG_DOUBLE = 6;
	const TAG_BYTE_ARRAY = 7;
	const TAG_STRING = 8;
	const TAG_LIST = 9;
	const TAG_COMPOUND = 10;

	public function loadFile($filename, $wrapper = "compress.zlib://") {
		if(is_string($wrapper) && is_file($filename)) {
			$this->getLogger()->addInfo("Loading file \"{$filename}\" with stream wrapper \"{$wrapper}\".");
			$fp = fopen("{$wrapper}{$filename}", "rb");
		} elseif(is_null($wrapper) && is_resource($filename)) {
			$this->getLogger()->addInfo("Loading file from existing resource.");
			$fp = $filename;
		} else {
			$this->getLogger()->addWarning("First parameter must be a filename or a resource.");
			trigger_error("First parameter must be a filename or a resource.", E_USER_WARNING);
			return false;
		}
		$this->getLogger()->addInfo("Traversing first tag in file.");
		$this->traverseTag($fp, $this->root);
		$this->getLogger()->addInfo("Encountered end tag for first tag; finished.");
		return end($this->root);
	}

	public function writeFile($filename, $wrapper = "compress.zlib://") {
		if(is_string($wrapper)) {
			$this->getLogger()->addInfo("Writing file \"{$filename}\" with stream wrapper \"{$wrapper}\".");
			$fp = fopen("{$wrapper}{$filename}", "wb");
		} elseif(is_null($wrapper) && is_resource($fp)) {
			$this->getLogger()->addInfo("Writing file to existing resource.");
			$fp = $filename;
		} else {
			$this->getLogger()->addWarning("First parameter must be a filename or a resource.");
			trigger_error("First parameter must be a filename or a resource.", E_USER_WARNING);
			return false;
		}
		$this->getLogger()->addInfo("Writing ".count($this->root)." root tag(s) to file/resource.");
		foreach($this->root as $rootNum => $rootTag) {
			if (!$this->writeTag($fp, $rootTag)) {
				$this->getLogger()->addWarning("Failed to write root tag #{$rootNum} to file/resource.");
				trigger_error("Failed to write root tag #{$rootNum} to file/resource.", E_USER_WARNING);
			}
		}
		return true;
	}

	public function purge() {
		$this->getLogger()->addError("Purging all loaded data");
		$this->root = [];
	}

	public function traverseTag($fp, &$tree) {
		if(feof($fp)) {
			$this->getLogger()->addInfo("Reached end of file/resource.");
			return false;
		}
		$tagType = $this->readType($fp, self::TAG_BYTE); // Read type byte.
		if($tagType == self::TAG_END) {
			return false;
		} else {
			$tagName = $this->readType($fp, self::TAG_STRING);
			$position = ftell($fp);
			$this->getLogger()->addInfo("Reading tag \"{$tagName}\" at offset {$position}.");
			$tagData = $this->readType($fp, $tagType);
			$tree[] = ['type' => $tagType, 'name' => $tagName, 'value' => $tagData];
			return true;
		}
	}

	public function writeTag($fp, $tag) {
		$position = ftell($fp);
		$this->getLogger()->addInfo("Writing tag \"{$tag["name"]}\" of type {$tag["type"]} at offset {$position}.");
		return $this->writeType($fp, self::TAG_BYTE, $tag["type"]) && $this->writeType($fp, self::TAG_STRING, $tag["name"]) && $this->writeType($fp, $tag["type"], $tag["value"]);
	}

	public function readType($fp, $tagType) {
		switch($tagType) {
			case self::TAG_BYTE: // Signed byte (8 bit)
				list(,$unpacked) = unpack("c", fread($fp, 1));
				return $unpacked;
			case self::TAG_SHORT: // Signed short (16 bit, big endian)
				list(,$unpacked) = unpack("n", fread($fp, 2));
				if($unpacked >= pow(2, 15)) $unpacked -= pow(2, 16); // Convert unsigned short to signed short.
				return $unpacked;
			case self::TAG_INT: // Signed integer (32 bit, big endian)
				list(,$unpacked) = unpack("N", fread($fp, 4));
				if($unpacked >= pow(2, 31)) $unpacked -= pow(2, 32); // Convert unsigned int to signed int
				return $unpacked;
			case self::TAG_LONG: // Signed long (64 bit, big endian)
				return $this->readTypeLong($fp);
			case self::TAG_FLOAT: // Floating point value (32 bit, big endian, IEEE 754-2008)
				list(,$value) = (pack('d', 1) == "\77\360\0\0\0\0\0\0")?unpack('f', fread($fp, 4)):unpack('f', strrev(fread($fp, 4)));
				return $value;
			case self::TAG_DOUBLE: // Double value (64 bit, big endian, IEEE 754-2008)
				list(,$value) = (pack('d', 1) == "\77\360\0\0\0\0\0\0")?unpack('d', fread($fp, 8)):unpack('d', strrev(fread($fp, 8)));
				return $value;
			case self::TAG_BYTE_ARRAY: // Byte array
				$arrayLength = $this->readType($fp, self::TAG_INT);
				$array = [];
				for($i = 0; $i < $arrayLength; $i++) $array[] = $this->readType($fp, self::TAG_BYTE);
				return $array;
			case self::TAG_STRING: // String
				if(!$stringLength = $this->readType($fp, self::TAG_SHORT)) return "";
				$string = utf8_decode(fread($fp, $stringLength)); // Read in number of bytes specified by string length, and decode from utf8.
				return $string;
			case self::TAG_LIST: // List
				$tagID = $this->readType($fp, self::TAG_BYTE);
				$listLength = $this->readType($fp, self::TAG_INT);
				$this->getLogger()->addInfo("Reading in list of {$listLength} tags of type {$tagID}.");
				$list = ['type' => $tagID, 'value' => []];
				for($i = 0; $i < $listLength; $i++) {
					if(feof($fp)) break;
					$list["value"][] = $this->readType($fp, $tagID);
				}
				return $list;
			case self::TAG_COMPOUND: // Compound
				$tree = [];
				while($this->traverseTag($fp, $tree));
				return $tree;
		}
	}

	/**
	 * Read a long value using GMP or bcmath if avaiable, otherwise compute manually
	 */
	private function readTypeLong($fp) {
		list(,$hi) = unpack('N', fread($fp, 4));
		list(,$lo) = unpack('N', fread($fp, 4));

		// on x64, we can just use int
		if (((int) 4294967296) != 0) {
			$this->getLogger()->addInfo('Reading long value using native support.');
			return (((int) $hi) << 32) + ((int) $lo);
		}

		// workaround signed/unsigned braindamage on x32
		$hi = sprintf('%u', $hi);
		$lo = sprintf('%u', $lo);

		// use GMP if possible
		if (function_exists('gmp_mul')) {
			$this->getLogger()->addInfo('Reading long value using GMP.');
			return gmp_strval(gmp_add(gmp_mul($hi, "4294967296"), $lo));
		}

		// use bcmath if possible
		if (function_exists('bcmul')) {
			$this->getLogger()->addInfo('Reading long value using bcmath.');
			return bcadd(bcmul($hi, '4294967296'), $lo);
		}

		$this->getLogger()->addInfo('Reading long value using manual calculation.');

		// compute everything manually
		$a = substr($hi, 0, -5);
		$b = substr($hi, -5);
		$ac = $a * 42949; // hope that float precision is enough
		$bd = $b * 67296;
		$adbc = $a * 67296 + $b * 42949;
		$r4 = substr($bd, -5) +  + substr($lo, - 5);
		$r3 = substr($bd, 0, -5) + substr($adbc, -5) + substr($lo, 0, -5);
		$r2 = substr($adbc, 0, -5) + substr($ac, -5);
		$r1 = substr($ac, 0, -5);

		while ($r4 > 100000) { $r4 -= 100000; $r3++; }
		while ($r3 > 100000) { $r3 -= 100000; $r2++; }
		while ($r2 > 100000) { $r2 -= 100000; $r1++; }

		$r = sprintf('%d%05d%05d%05d', $r1, $r2, $r3, $r4);
		$l = strlen($r);

		$i = 0;

		while ($r[$i] == "0" && $i < $l - 1) {
			$i++;
		}

		return substr($r, $i);
	}

	private function writeLongValue($fp, $value) {
		// on x64, we can just use int
		if (((int) 4294967296) != 0) {
			$highMap = 0xffffffff00000000;
			$lowMap = 0x00000000ffffffff;

			$hi = ($value & $highMap) >> 32;
			$lo = $value & $lowMap;

			$this->getLogger()->addInfo('Writing long value using native support.', ['value' => $value, 'higher' => $hi, 'lower' => $lo]);
		} else if (function_exists('gmp_mul')) {
			$hi = gmp_mul(gmp_and($value, '-4294967296'), gmp_pow(2, 32));
			$lo = gmp_and($value, '4294967295');

			$this->getLogger()->addInfo('Writing long value using GMP.', ['value' => $value, 'higher' => gmp_strval($hi), 'lower' => gmp_strval($lo)]);
		}

		return is_int(fwrite($fp, pack('N', $hi))) && is_int(fwrite($fp, pack('N', $lo)));
	}

	public function writeType($fp, $tagType, $value) {
		switch($tagType) {
			case self::TAG_BYTE: // Signed byte (8 bit)
				return is_int(fwrite($fp, pack("c", $value)));
			case self::TAG_SHORT: // Signed short (16 bit, big endian)
				if($value < 0) $value += pow(2, 16); // Convert signed short to unsigned short
				return is_int(fwrite($fp, pack("n", $value)));
			case self::TAG_INT: // Signed integer (32 bit, big endian)
				if($value < 0) $value += pow(2, 32); // Convert signed int to unsigned int
				return is_int(fwrite($fp, pack("N", $value)));
			case self::TAG_LONG: // Signed long (64 bit, big endian)
				return $this->writeLongValue($fp, $value);
			case self::TAG_FLOAT: // Floating point value (32 bit, big endian, IEEE 754-2008)
				return is_int(fwrite($fp, (pack('d', 1) == "\77\360\0\0\0\0\0\0")?pack('f', $value):strrev(pack('f', $value))));
			case self::TAG_DOUBLE: // Double value (64 bit, big endian, IEEE 754-2008)
				return is_int(fwrite($fp, (pack('d', 1) == "\77\360\0\0\0\0\0\0")?pack('d', $value):strrev(pack('d', $value))));
			case self::TAG_BYTE_ARRAY: // Byte array
				return $this->writeType($fp, self::TAG_INT, count($value)) && is_int(fwrite($fp, call_user_func_array("pack", array_merge(['c'.count($value)], $value))));
			case self::TAG_STRING: // String
				$value = utf8_encode($value);
				return $this->writeType($fp, self::TAG_SHORT, strlen($value)) && is_int(fwrite($fp, $value));
			case self::TAG_LIST: // List
				$this->getLogger()->addInfo("Writing list of ".count($value["value"])." tags of type {$value["type"]}.");
				if(!($this->writeType($fp, self::TAG_BYTE, $value["type"]) && $this->writeType($fp, self::TAG_INT, count($value["value"])))) return false;
				foreach($value["value"] as $listItem) if(!$this->writeType($fp, $value["type"], $listItem)) return false;
				return true;
			case self::TAG_COMPOUND: // Compound
				foreach($value as $listItem) if(!$this->writeTag($fp, $listItem)) return false;
				if(!is_int(fwrite($fp, "\0"))) return false;
				return true;
		}
	}

	public function setDebug($debug) {
		if ($debug) {
			$this->getLogger()->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
		} else {
			if (count($this->getLogger()->getHandlers()) > 1) {
				$this->getLogger()->popHandler();
			}
		}
	}

	protected function getLogger() {
		if ($this->logger === null) {
			// Create the logger with a default handler pointing to stderr for Warning or higher messages
			$this->logger = new Logger('NBT_Logger', [ new StreamHandler('php://stderr', Logger::WARNING)]);
		}

		return $this->logger;
	}
}
?>
