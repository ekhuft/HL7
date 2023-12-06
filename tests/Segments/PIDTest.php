<?php

declare(strict_types=1);

namespace Aranyasen\HL7\Tests\Segments;

use Aranyasen\Exceptions\HL7Exception;
use Aranyasen\HL7\Message;
use Aranyasen\HL7\Segments\MSH;
use Aranyasen\HL7\Segments\PID;
use Aranyasen\HL7\Tests\TestCase;
use InvalidArgumentException;

class PIDTest extends TestCase
{
    /**
     * @dataProvider validSexValues
     * @test
     */
    public function PID_8_should_accept_value_for_sex_as_per_hl7_standard(string $validSexValue): void
    {
        $pidSegment = (new PID());
        $pidSegment->setSex($validSexValue);
        self::assertSame($validSexValue, $pidSegment->getSex(), "Sex should have been set with '$validSexValue'");
        self::assertSame($validSexValue, $pidSegment->getField(8), "Sex should have been set with '$validSexValue'");
    }

    /**
     * @dataProvider invalidSexValues
     * @test
     * @param  string  $invalidSexValue
     */
    public function PID_8_should_not_accept_non_standard_values_for_sex(string $invalidSexValue): void
    {
        $pidSegment = (new PID());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Sex should one of 'A', 'F', 'M', 'N', 'O' or 'U'. Given: '$invalidSexValue'");
        $pidSegment->setSex($invalidSexValue);
        self::assertEmpty($pidSegment->getSex(), "Sex should not have been set with value: $invalidSexValue");
        self::assertEmpty($pidSegment->getField(8), "Sex should not have been set with value: $invalidSexValue");
    }

    /** @test
     * @throws HL7Exception
     */
    public function PID_5_should_parse_properly(): void
    {
        $messageString = "MSH|^~\\&|1|\r" .
            'PID|||||' .
            'Test &""&firstname &""&""^F^N N^^^^F^^^^""~Test &""&Test^Test^""^^^^B~""&""&""^^^^^^P~^Lastname^^^^^N|' .
            "\r";
        $msg = new Message($messageString, null, false, true, true, true);

        self::assertSame(
            'MSH|^~\&|1|\nPID|1||||Test &""&firstname &""&""^F^N N^F^""~Test &""&Test^Test^""^B~""&""&""^P~^' .
            'Lastname^N|\n',
            $msg->toString()
        );
    }

    /**
     * @test
     */
    public function PID_3_should_accept_array_of_arrays(): void
    {
        $opts = [
            'SEGMENT_SEPARATOR' => "\n",
            'SEGMENT_ENDING_BAR' => true,
            'FIELD_SEPARATOR' => '|',
            'COMPONENT_SEPARATOR' => '^',
            'SUBCOMPONENT_SEPARATOR' => '^',
            'REPETITION_SEPARATOR' => '~',
            'ESCAPE_CHARACTER' => '\\',
            'HL7_VERSION' => '2.3',
        ];

        // Create a Message with MSH
        $msg = new Message(null, $opts);

        $msh = new MSH(null, $opts);
        $msg->addSegment($msh);

        // Create a PID with multiple identifiers
        $pid = new PID;

        $pid->setPatientID('12345');

        $pid->setPatientIdentifierList([
            [ '12345', '', '', '', 'AB' ],
            [ '56789', '', '', '', 'CD' ],
        ]);

        $msg->addSegment($pid);

        $this->assertStringContainsString('PID|1|12345|12345^^^^AB~56789^^^^CD|', $msg->toString());

        // Assert that we can accurately decode, too...
        $parsed = new Message($msg->toString(), $opts);

        $parsedPID = $parsed->getFirstSegmentInstance('PID');

        $identifiers = $parsedPID->getPatientIdentifierList();

        $this->assertCount(2, $identifiers);

        $this->assertEquals('12345', $identifiers[0][0]);
        $this->assertEquals('56789', $identifiers[1][0]);
    }

    public function validSexValues(): array
    {
        return [
            ['A'], ['F'], ['M'], ['N'], ['O'], ['U']
        ];
    }

    public function invalidSexValues(): array
    {
        return [
           ['B'], ['Z'], ['z'], ['a']
        ];
    }
}
