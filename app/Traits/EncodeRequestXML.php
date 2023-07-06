<?php
/**
 * Apply to children of AbstractRequest to prepare request's body and Content-Type headers as XML
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use SimpleXMLElement;

trait EncodeRequestXML
{
    /**
     * Include Content-Type headers on $this->headers
     */
    public function setContentHeaders(): void
    {
        $this->headers['Content-Type'] = 'text/xml';
    }

    /**
     * If $this->body is a SimpleXMLElement, encode it into an XML string.
     * Otherwise, just pass it through to Guzzle
     *     Most users of this trait were already encoding the XML outside the request,
     *     A rare few like PorscheBuilder actually stringify the XML *then modify the string*
     */
    public function encodeBody(): string
    {
        if ($this->body instanceof SimpleXMLElement) {
            return $this->body->asXML();
        }
        return $this->body;
    }
}
