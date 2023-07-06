<?php
/**
 * Apply to children of AbstractRequest to prepare request's body and Content-Type headers as JSON-encoded GraphQL
 */
declare(strict_types=1);

namespace Carsdotcom\ApiRequest\Traits;

use Carsdotcom\ApiRequest\Exceptions\NotImplementedException;
use Carsdotcom\ApiRequest\Helpers;

trait EncodeRequestGraphQL
{
    /**
     * An associative array of GraphQL variables
     */
    protected array $variables = [];

    /**
     * Use a static file or a dynamic query builder to implement the GraphQL Query
     * @return string
     * @throws NotImplementedException
     */
    protected function getGraphQLQuery(): string
    {
        throw new NotImplementedException(
            Helpers::friendlyClassName(__CLASS__) . ' must implement the method getGraphQLQuery',
        );
    }

    /**
     * Include Content-Type headers on $this->headers
     */
    public function setContentHeaders(): void
    {
        $this->headers['Content-Type'] = 'application/json';
    }

    /**
     * Encode $this->body into a JSON-style GraphQL body
     */
    public function encodeBody(): string
    {
        return json_encode([
            'query' => $this->getGraphQLQuery(),
            'variables' => (object) $this->variables,
        ]);
    }
}
