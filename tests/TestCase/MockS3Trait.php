<?php

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Configures the S3 clients of pieceofcake2/aws and pieceofcake2/async-aws with mock handlers, and records the
 * requests that they send. No request is sent to AWS.
 */
trait MockS3Trait
{
    /**
     * Results of the AWS SDK, returned in order: a `Result`, or a callable that receives the command and returns
     * a `Result` or an exception. When none is left, an empty `Result` is returned.
     *
     * @var array<\Aws\Result|callable>
     */
    protected array $awsResults = [];

    /**
     * Commands sent by the AWS SDK, each with `command` (name), `params` and `request` (the signed request).
     *
     * @var array<array{command: string, params: array, request: \Psr\Http\Message\RequestInterface}>
     */
    protected array $awsCommands = [];

    /**
     * Responses of AsyncAws, returned in order. When none is left, an empty response is returned.
     *
     * @var array<\Symfony\Component\HttpClient\Response\MockResponse>
     */
    protected array $asyncAwsResponses = [];

    /**
     * Requests sent by AsyncAws, each with `method`, `url`, `headers` (lower-case names) and `body`.
     *
     * @var array<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    protected array $asyncAwsRequests = [];

    /**
     * Writes the `Aws` configuration with static credentials and a mock handler.
     *
     * @param array $config Configuration merged into the defaults.
     * @return void
     */
    protected function mockAws(array $config = []): void
    {
        $this->awsResults = [];
        $this->awsCommands = [];
        Configure::write('Aws', $config + [
            'region' => 'ap-northeast-1',
            'credentials' => ['key' => 'aws-key', 'secret' => 'aws-secret'],
            'handler' => function (CommandInterface $command, RequestInterface $request) {
                $this->awsCommands[] = ['command' => $command->getName(), 'params' => $command->toArray(), 'request' => $request];
                $result = array_shift($this->awsResults) ?? new Result([]);
                if (is_callable($result)) {
                    $result = $result($command);
                }

                return $result instanceof Throwable ? Create::rejectionFor($result) : Create::promiseFor($result);
            },
        ]);
    }

    /**
     * Writes the `AsyncAws` configuration with static credentials and a mock HTTP client.
     *
     * @param array $config Configuration merged into the defaults.
     * @return void
     */
    protected function mockAsyncAws(array $config = []): void
    {
        $this->asyncAwsResponses = [];
        $this->asyncAwsRequests = [];
        Configure::write('AsyncAws', $config + [
            'region' => 'ap-northeast-1',
            'accessKeyId' => 'async-aws-key',
            'accessKeySecret' => 'async-aws-secret',
            'httpClient' => new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
                $headers = [];
                foreach ($options['normalized_headers'] ?? [] as $name => $values) {
                    $headers[$name] = substr((string)$values[0], strlen($name) + 2);
                }
                $body = $options['body'] ?? '';
                if (is_callable($body)) {
                    $chunks = '';
                    while (($chunk = $body(16384)) !== '') {
                        $chunks .= $chunk;
                    }
                    $body = $chunks;
                } elseif (is_iterable($body)) {
                    $body = implode('', iterator_to_array($body, false));
                }
                $this->asyncAwsRequests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];

                return array_shift($this->asyncAwsResponses) ?? new MockResponse('');
            }),
        ]);
    }

    /**
     * Returns the names of the commands sent by the AWS SDK.
     *
     * @return array<string>
     */
    protected function awsCommandNames(): array
    {
        return array_column($this->awsCommands, 'command');
    }

    /**
     * Returns the last command sent by the AWS SDK.
     *
     * @return array{command: string, params: array, request: \Psr\Http\Message\RequestInterface}
     */
    protected function lastAwsCommand(): array
    {
        $this->assertNotEmpty($this->awsCommands, 'No command was sent.');

        return end($this->awsCommands);
    }

    /**
     * Returns the last request sent by AsyncAws.
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    protected function lastAsyncAwsRequest(): array
    {
        $this->assertNotEmpty($this->asyncAwsRequests, 'No request was sent.');

        return end($this->asyncAwsRequests);
    }
}
