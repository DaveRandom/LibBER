<?php

namespace LibBER;

use LibASN1\Type,
    LibASN1\Boolean,
    LibASN1\Integer,
    LibASN1\OctetString,
    LibASN1\Null;

class Encoder
{
    const OPT_TRUE_VALUE = 0x01;
    const OPT_FORCE_DEFINITE_LENGTH = 0x02;

    private $options = [
        self::OPT_TRUE_VALUE => 0xFF,
        self::OPT_FORCE_DEFINITE_LENGTH => false,
    ];

    private static $intFirstBitOffset;
    private static $idFirstBitOffset;

    private function castFromTarget($value, $target)
    {
        switch (true) {
            case is_bool($target):
                return (bool) $value;

            case is_int($target):
                return (int) $value;

            case is_float($target):
                return (float) $value;

            case is_string($target):
                return (string) $value;

            case is_array($target):
                return (array) $value;

            case is_object($target):
                return (object) $value;

            default:
                return $value;
        }
    }

    private function getIntegerBytes($int)
    {
        $bytes = [];

        if ($value === 0) {
            $bytes[] = 0x00;
        } else if ($value === -1) {
            $bytes[] = 0xFF;
        } else {
            if ($value < 0) {
                for ($i = self::$intFirstBitOffset; $i >= 0 && (($value >> $i) & 0xFF) === 0xFF; $i -= 8);

                if (!((($value >> $i) & 0xFF) & 0x80)) {
                    $bytes[] = 0xFF;
                }
            } else {
                for ($i = self::$intFirstBitOffset; $i >= 0 && ($value >> $i) === 0x00; $i -= 8);

                if ((($value >> $i) & 0xFF) & 0x80) {
                    $bytes[] = 0x00;
                }
            }

            for (; $i >= 0; $i -= 8) {
                $bytes[] = ($value >> $i) & 0xFF;
            }
        }

        return $bytes;
    }

    private function encodeDefiniteLength($length)
    {
        if ($length < 128) {
            return pack('C', $length);
        } else {
            $bytes = $this->getIntegerBytes($length);
            return call_user_func_array('pack', array_merge(['C*', 0x80 | count($bytes)], $bytes));
        }
    }

    private function encodeIdentifier($class, $isConstructed, $number)
    {
        $args = [
            'C*',
            (($class & 0x03) << 6) | (((int)(bool) $isConstructed) << 5)
        ];

        if ($number < 31) {
            $args[1] |= $number & 0x1F;
        } else {
            $args[1] |= 0x1F;

            for ($i = self::$idFirstBitOffset; $i && !($number >> $i); $i -= 7);

            for ($k = 2; $i >= 0; $i -= 7, $k++) {
                $args[$k] = (($number >> $i) & 0x7F) | 0x80;
            }

            $args[$k - 1] = $args[$k - 1] & 0x7F;
        }

        return call_user_func_array('pack', $args);
    }

    private function encodeIdentifierFromType(Type $data)
    {
        return $this->encodeIdentifier($data->getClass(), $data->isConstructed(), $data->getNumber());
    }

    private function output($data, OutputStream $outputStream = null, &$result = null)
    {
        if ($outputStream !== null && !$this->options[self::OPT_FORCE_DEFINITE_LENGTH]) {
            $outputStream->write($result . $data);
            $result = null;
        } else {
            $result .= $data;
        }

        return $result;
    }

    public function __construct(array $options = [])
    {
        foreach ($options as $opt => $value) {
            $this->setOption($opt, $value);
        }

        if (!isset(self::$intFirstBitOffset)) {
            self::$intFirstBitOffset = (PHP_INT_SIZE - 1) * 8;
            self::$idFirstBitOffset = 7 * floor((PHP_INT_SIZE * 8) / 7);
        }
    }

    public function setOption($option, $value)
    {
        if (!array_key_exists($option, $this->options)) {
            throw new \OutOfRangeException('Unknown option identifier ' . $option);
        }

        $this->options[$option] = $this->castFromTarget($value, $this->options[$option]);
    }

    public function getOption($option)
    {
        if (!array_key_exists($option, $this->options)) {
            throw new \OutOfRangeException('Unknown option identifier ' . $option);
        }

        return $this->options[$option];
    }

    public function encode(Type $data, OutputStream $outputStream = null)
    {
        switch (true) {
            case $data instanceof Boolean:
                return $this->encodeBoolean($data, $outputStream);

            case $data instanceof Integer:
            case $data instanceof Enumeration:
                return $this->encodeInteger($data, $outputStream);

            case $data instanceof OctetString:
                return $this->encodeOctetString($data, $outputStream);

            case $data instanceof Null:
                return $this->encodeNull($data, $outputStream);

            case $data instanceof Sequence:
                return $this->encodeSequence($data, $outputStream);
        }
    }

    public function encodeBoolean(Boolean $data, OutputStream $outputStream = null)
    {
        return $this->output(
            $this->encodeIdentifierFromType($data) . "\x01" . chr($data->getValue() ? $this->options[self::OPT_TRUE_VALUE] : 0),
            $outputStream
        );
    }

    public function encodeInteger(Integer $data, OutputStream $outputStream = null)
    {
        $bytes = $this->getIntegerBytes($data->getValue());

        return $this->output(
            $this->encodeIdentifierFromType($data) . call_user_func_array('pack', array_merge(['C*', count($bytes)], $bytes)),
            $outputStream
        );
    }

    public function encodeOctetString(OctetString $data, OutputStream $outputStream = null)
    {
        if ($string->isConstructed() && !$this->options[self::OPT_FORCE_DEFINITE_LENGTH]) {
            $result = $this->encodeIdentifierFromType($data);

            while ($data->hasMore()) {
                $raw = $data->getChunk();

                $chunk = $this->encodeIdentifier(Type::CLASS_UNIVERSAL, false, 4)
                       . $this->encodeDefiniteLength(strlen($raw))
                       . $raw;

                $this->output($chunk, $outputStream, $result);
            }

            $this->output("\x00\x00", $outputStream, $result);
        } else {
            $raw = '';
            while ($data->hasMore()) {
                $raw .= $data->getChunk();
            }

            $result = $this->encodeIdentifierFromType($data);
                    . $this->encodeDefiniteLength(strlen($raw))
                    . $raw;

            $this->output($result, $outputStream);
        }

        return $result;
    }

    public function encodeNull(Null $data, OutputStream $outputStream = null)
    {
        return $this->output(
            $this->encodeIdentifierFromType($data) . "\x00",
            $outputStream
        );
    }

    public function encodeSequence(Sequence $data, OutputStream $outputStream = null)
    {
        return $this->output(
            $this->encodeIdentifierFromType($data) . "\x00",
            $outputStream
        );
    }
}

spl_autoload_register(function($className) {
    require dirname(__DIR__) . '/' . $className . '.php';
});

$enc = new Encoder;
$int = new Boolean(0);
echo bytes($enc->encode($int));

function bytes($str)
{
    $asc = $dec = $hex = $bin = [];

    foreach (str_split($str) as $i => $byte) {
        $dec[$i] = str_pad($byte = ord($byte), 9, ' ', STR_PAD_BOTH);
        $hex[$i] = str_pad(sprintf('%02X', $byte), 9, ' ', STR_PAD_BOTH);
        $bin[$i] = str_pad(sprintf('%08b', $byte), 9, ' ', STR_PAD_BOTH);
    }

    return implode('', $dec) . "\n" . implode('', $hex) . "\n" . implode('', $bin);
}
