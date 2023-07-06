<?php
/**
 * Apply to children of AbstractRequest to prepare request's body and Content-Type headers as JSON
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

trait EncodeRequestJSON
{
    /**
     * Include Content-Type headers on $this->headers
     */
    public function setContentHeaders(): void
    {
        $this->headers['Content-Type'] = 'application/json';
    }

    /**
     * Encode $this->body (which can be almost anything) into a JSON format string
     */
    public function encodeBody(): string
    {
        return json_encode($this->body);
    }
}
