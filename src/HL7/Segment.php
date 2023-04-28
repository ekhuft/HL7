<?php

declare(strict_types=1);

namespace Aranyasen\HL7;

use Exception;
use InvalidArgumentException;

class Segment
{
    protected array $fields = [];

    protected ?EscapeSequenceHandler $escapeSequenceHandler = null;

    /**
     * Create a segment.
     *
     * A segment may be created with just a name or a name and an array of field values. The segment name should be a
     * standard HL7 segment (e.g. MSH / PID etc.) that is three characters long, and upper case. If an array is given,
     * all fields will be filled from that array. Note that for composed fields and sub-components, the array may hold
     * sub-arrays and sub-sub-arrays. Repeated fields can not be supported the same way, since we can't distinguish
     * between composed fields and repeated fields.
     *
     * Example:
     * ```php
     * $seg = new Segment("PID");
     *
     * $seg->setField(3, "12345678");
     * echo $seg->getField(1);
     * ```
     *
     * @author     Aranya Sen
     * @param string $name Name of the segment
     * @param array|null $fields Fields for segment
     * @throws InvalidArgumentException
     */
    public function __construct(string $name, array $fields = null)
    {
        // Is the name 3 upper case characters?
        if ((!$name) || (strlen($name) !== 3) || (strtoupper($name) !== $name)) {
            throw new InvalidArgumentException("Segment name '$name' should be 3 characters and in uppercase");
        }

        $this->fields[0] = $name;

        if (is_array($fields)) {
            foreach ($fields as $i => $value) {
                $this->setField($i + 1, $value);
            }
        }
    }

    /**
     * Set the field specified by index to value.
     *
     * Indices start at 1, to stay with the HL7 standard. Trying to set the
     * value at index 0 has no effect. The value may also be a reference to an array (that may itself contain arrays)
     * to support composite fields (and sub-components).
     *
     * Examples:
     * ```php
     * $segment->setField(18, 'abcd'); // Sets 18th field to abcd
     * $segment->setField(8, 'ab^cd'); // Sets 8th field to ab^cd
     * $segment->setField(10, ['John', 'Doe']); // Sets 10th field to John^Doe
     * $segment->setField(12, ['']); // Sets 12th field to ''
     * $segment->setField(8, 'ab|cd', true); // Sets 8th field to ab\F\cd
     * ```
     *
     * If values are not provided at all, the method will just return.
     *
     * @param int $index Index to set
     * @param bool $escape Whether to escape special characters. Default is false for backwards compatibility.
     */
    public function setField(int $index, string|int|array|null $value = '', bool $escape = false): bool
    {
        if ($index === 0) { // Do not allow changing 0th index, which is the name of the segment
            return false;
        }

        if ($this->isValueEmpty($value)) {
            return false;
        }

        // Fill in the blanks...
        for ($i = count($this->fields); $i < $index; $i++) {
            $this->fields[$i] = '';
        }

        $this->fields[$index] = ($this->hasEscapeSequenceHandler() || $escape === true)
            ? $this->getEscapeSequenceHandler()->escape($value)
            : $value;

        return true;
    }

    private function isValueEmpty($value): bool
    {
        if (is_array($value)) {
            return empty($value);
        }

        if ((string) $value === '0') { // Allow 0
            return false;
        }

        return ! $value;
    }

    /**
     * Remove any existing value from the field
     *
     * @param int $index Field index
     */
    public function clearField(int $index): void
    {
        $this->fields[$index] = null;
    }

    /**
     * Get the field at index.
     *
     * If the field is a composite field, it returns an array
     * Example:
     * ```php
     * $field = $seg->getField(9); // Returns a string/null/array depending on what the 9th field is.
     * ```
     *
     * @param bool $unescape Whether to unescape special characters
     */
    public function getField(int $index, bool $unescape = false): array|string|int|null
    {
        if (!array_key_exists($index, $this->fields)) {
            return null;
        }

        return ($this->hasEscapeSequenceHandler() || $unescape === true)
            ? $this->getEscapeSequenceHandler()->unescape($this->fields[$index])
            : $this->fields[$index];
    }

    /**
     * Get the number of fields for this segment, not including the name
     *
     * @return int number of fields
     */
    public function size(): int
    {
        return count($this->fields) - 1;
    }

    /**
     * Get fields from a segment
     *
     * Get the fields in the specified range, or all if nothing specified. If only the 'from' value is provided, all
     * fields from this index till the end of the segment will be returned.
     *
     * @param int $from Start range at this index
     * @param int|null $to Stop range at this index
     * @param bool $unescape Whether to unescape special characters. Default is false for backwards compatibility.
     * @return array List of fields
     */
    public function getFields(int $from = 0, int $to = null, bool $unescape = false): array
    {
        if (!$to) {
            $to = count($this->fields);
        }

        $fields = array_slice($this->fields, $from, $to - $from + 1);

        return ($this->hasEscapeSequenceHandler() || $unescape === true)
            ? array_map([ $this->getEscapeSequenceHandler(), 'unescape' ], $fields)
            : $fields;
    }

    /**
     * Get the name of the segment. This is basically the value at index 0
     *
     * @return string Name of segment
     */
    public function getName(): string
    {
        return $this->fields[0];
    }

    /**
     * Check if escape sequence handler is set
     *
     * @return bool
     */
    protected function hasEscapeSequenceHandler(): bool
    {
        return !is_null($this->escapeSequenceHandler);
    }

    /**
     * Set the escape sequence handler
     *
     * @param EscapeSequenceHandler $escapeSequenceHandler
     */
    public function setEscapeSequenceHandler(EscapeSequenceHandler $escapeSequenceHandler): self
    {
        if ($this->hasEscapeSequenceHandler()) {
            // Changing EscapeSequenceHandler can result in malformed results.
            throw new Exception("Segment EscapeSequenceHandler has already been set.");
        }

        $this->escapeSequenceHandler = $escapeSequenceHandler;

        return $this;
    }

    /**
     * Get escape sequence handler instance
     *
     * @return EscapeSequenceHandler
     */
    protected function getEscapeSequenceHandler(): EscapeSequenceHandler
    {
        return $this->escapeSequenceHandler ?? new EscapeSequenceHandler('\\');
    }
}
