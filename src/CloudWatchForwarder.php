<?php

/**
 * BetterStack CloudWatch Forwarder
 * Handles forwarding CloudWatch logs to BetterStack (Logtail)
 */

namespace MalterTech\BetterStack;

use Curl\Curl;

class CloudWatchForwarder
{

    /**
     * @param string $sourceToken  The BetterStack (Logtail) Source Token
     * @param string $ingestionURL The BetterStack Ingestion URL
     * @param string $sourceName   The identifier for this app (e.g. "Client-API-01")
     */
    public function __construct(
        private string $sourceToken,
        private string $ingestionURL,
        private string $sourceName
    ) {}

    /**
     * Process the CloudWatch payload and push it to BetterStack
     *
     * @param array $payload The raw event data from AWS
     * @return void
     */
    public function push(array $payload): void
    {
        // 1. Base64 decode
        if (!isset($payload["awslogs"]["data"])) {
            return;
        }

        $decodedData = base64_decode($payload["awslogs"]["data"]);

        // 2. Gzip decompress
        $decompressedData = gzdecode($decodedData);

        if ($decompressedData === false) {
            return;
        }

        // 3. JSON decode
        $parsedData = json_decode($decompressedData, true);

        if (!isset($parsedData['logEvents'])) {
            return;
        }

        $events = [];

        foreach ($parsedData["logEvents"] as $event) {
            // Skip Lambda start/stop request messages
            if (isset($event["message"]) && str_contains($event["message"], "RequestId:")) {
                continue;
            }

            // Check if message is valid JSON (AWS API Gateway logs) or String (Lambda logs)
            // Note: json_validate requires PHP 8.3+
            if (isset($event["message"]) && json_validate($event["message"])) {
                $parsedEvent = json_decode($event["message"], true);
            } else {
                $parsedEvent = ["message" => $event["message"] ?? ""];
            }

            // Enrich the log with the source name defined in constructor
            $parsedEvent["api_name"] = $this->sourceName;

            $events[] = $parsedEvent;
        }

        if (empty($events)) {
            return;
        }

        // 4. Send to BetterStack (Curl created on demand)
        $curl = new Curl();
        $curl->setHeader("Authorization", "Bearer {$this->sourceToken}");
        $curl->setHeader("Content-Type", "application/json");
        $curl->post($this->ingestionURL, $events);
        $curl->close();
    }
}