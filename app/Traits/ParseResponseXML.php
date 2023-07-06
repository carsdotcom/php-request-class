<?php
/**
 * Transform the response body from an XML string to a SimpleXMLElement
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use SimpleXMLElement;

trait ParseResponseXML
{
    /**
     * Given a string response body from Guzzle, parse it into a SimpleXMLElement
     * @param string $responseString
     * @return SimpleXMLElement
     * @throws \Exception if the string cannot be parsed as XML
     */
    public function parseResponseString(string $responseString): SimpleXMLElement
    {
        return new SimpleXMLElement($responseString);
    }
}
