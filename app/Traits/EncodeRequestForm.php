<?php
/**
 * Apply to children of AbstractRequest to prepare request's body and Content-Type headers as URL-Form encoded
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

trait EncodeRequestForm
{
    /**
     * Include Content-Type headers on $this->headers
     */
    public function setContentHeaders(): void
    {
        $this->headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }

    /**
     * Encode $this->body (which should be an associative array) into a urlencoded format string
     */
    public function encodeBody(): string
    {
        return http_build_query($this->body);
    }
}
