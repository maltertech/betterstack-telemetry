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
        // Base64 decode
        if (!isset($payload["awslogs"]["data"])) {
            return;
        }
        $decodedData = base64_decode($payload["awslogs"]["data"]);

        // Gzip decompress
        $decompressedData = gzdecode($decodedData);
        if ($decompressedData === false) {
            return;
        }

        // json decode
        $parsedData = json_decode($decompressedData, true);
        if (!isset($parsedData['logEvents'])) {
            return;
        }

        // extract logs
        $events = [];

        // Each event in the logEvents array has an id, timestamp and message key.
        /* Example below
        {
            "logEvents": [
                {
                    "id": "35286272357882262515405211100061592492480036482215494656",
                    "timestamp": 1631531111000,
                    "message": "NOTICE: PHP message: PHP Caught Error: Webhook skipped"
                },
                {
                    "id": "35286272357882262515405211100061592492480036482215494657",
                    "timestamp": 1631531111000,
                    "message": "{ \"duration\": 14, \"path\": \"/\", \"requestId\": \"TajXi_KyKL4EMoA=\", \"status\": 200 }"
                }
            ],
         */
        foreach ($parsedData["logEvents"] as $event) {
            // Skip Lambda start/stop request messages
            if (isset($event["message"]) && str_contains($event["message"], "RequestId:")) {
                continue;
            }

            // There are two types of messages:
            // 1) Lambda Format (String): NOTICE: PHP message: PHP Caught Error: Webhook skipped
            // 2) API Gateway Format (JSON String): { "duration": 14, "path": "/", "requestId": "TajXi_KyKL4EMoA=", "status": 200 }

            // check if the message is a valid JSON, and if so, push it to the events array, otherwise push it as a string
            if (isset($event["message"]) && json_validate($event["message"])) {
                $parsedEvent = json_decode($event["message"], true);
            } else {
                $parsedEvent = ["message" => $event["message"] ?? ""];
            }

            // Enrich the log with the source name defined in constructor
            $parsedEvent["api_name"] = $this->sourceName;

            $events[] = $parsedEvent;
        }

        // If no events to send, return
        if (empty($events)) {
            return;
        }

        // send to BetterStack, #1/#2 example: [{"message":"NOTICE: PHP message: PHP Caught Error: Webhook skipped"},{ "duration": 14, "path": "/", "requestId": "TajXi_KyKL4EMoA=", "status": 200 }]
        $curl = new Curl();
        $curl->setHeader("Authorization", "Bearer $this->sourceToken");
        $curl->setHeader("Content-Type", "application/json");
        $curl->post($this->ingestionURL, $events);
        $curl->close();
    }
}