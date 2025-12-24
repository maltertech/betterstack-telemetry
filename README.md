# BetterStack Telemetry for AWS CloudWatch

A lightweight, zero-dependency PHP library designed to forward **AWS CloudWatch Logs** directly to **BetterStack Telemetry (Logtail)**.

This package handles the specific format of AWS Lambda log subscription filters:
1.  **Decodes** Base64 payload.
2.  **Decompresses** Gzip data.
3.  **Parses** JSON log events.
4.  **Filters** standard Lambda noise (start/end requests).
5.  **Ships** structured logs to BetterStack.

## Requirements

* PHP 8.3+
* `ext-json`
* `ext-zlib`
* `php-curl-class/php-curl-class`

## Installation

Install the package via Composer:

```bash
composer require maltertech/betterstack-telemetry
```

## Usage

This library is designed to be used inside an AWS Lambda function (or any PHP application receiving raw CloudWatch log streams).

### 1. Initialization

Initialize the forwarder with your BetterStack credentials. Ideally, do this in your application's Service Provider or Dependency Injection container.

```php
use MalterTech\BetterStack\CloudWatchForwarder;

$logger = new CloudWatchForwarder(
    sourceToken:  'BETTERSTACK_TOKEN',      // Your Source Token from BetterStack
    ingestionUrl: 'https://in.logs.betterstack.com', // The Ingestion URL
    sourceName:   'Client-API-01'                    // Identifier for this logs source
);
```

### 2. Processing Events

Pass the raw AWS event payload to the `push` method. The library will automatically detect if the payload contains compressed `awslogs` data and process it.

```php
// Inside your Lambda Handler or Controller
public function handle(array $event)
{
    // ... your application logic ...

    // Forward logs to BetterStack
    $logger->push($event);
}
```

## How it Works

When AWS CloudWatch streams logs to a Lambda function, it sends them in a compressed format:
`{ "awslogs": { "data": "H4sIAAAAAAAA/..." } }`

The `CloudWatchForwarder` performs the following steps:
1.  **Extraction:** target the `['awslogs']['data']` key.
2.  **Decompression:** `base64_decode` -> `gzdecode`.
3.  **Parsing:** Iterates through `logEvents`.
4.  **Cleaning:** Skips strictly internal Lambda messages (like `RequestId: ...`) to save quota.
5.  **Formatting:** Checks if the log message itself is JSON (e.g., from API Gateway) and parses it into a structured object; otherwise, treats it as a string.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.