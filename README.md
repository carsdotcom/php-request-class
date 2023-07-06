# php-request-class
Structure the logic around Guzzle API requests into object-oriented classes

## What?
We maintain an application that is reliant on dozens of REST APIs, most of which do not have SDKs.

We love Guzzle! But most of the documentation assumes that making a request is "easy," just a few lines of code.

But we've discovered in our own use that the structure around that web request can run into hundreds of lines, 
managing pre-requisites like authentication, converting data between our internal types and our partners' types, etc.

So we created `AbstractRequest` as a standardized way to:
1. Organize logic into classes.
2. Provide swappable, traits for encoding and decoding
3. Log *everything* using Laravel's File facade
4. Cache using Laravel's Cache facade (with a simple automatic cache key generator)
5. Provide a structure for chainable prerequisites (like authentication)
6. Provide a structure for catching exceptions and parsing responses back into your internal logic
7. Let you defer decisions about call order or synchronous/asynchronous processing to the user of the request class
