<?php

declare (strict_types=1);
/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Symfony\Flex;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Response implements \JsonSerializable
{
    private array $headers;
    /**
     * @param mixed $body The response as JSON
     */
    public function __construct(private $body, private readonly array $orig_headers = [], private readonly int $code = 200)
    {
        $this->headers = $this->parse_headers($this->orig_headers);
    }
    public function get_status_code(): int
    {
        return $this->code;
    }
    public function get_header(string $name): string
    {
        return $this->headers[strtolower($name)][0] ?? '';
    }
    public function get_headers(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
    public function get_body()
    {
        return $this->body;
    }
    public function get_orig_headers(): array
    {
        return $this->orig_headers;
    }
    public static function from_json(array $json): self
    {
        $response = new self($json['body']);
        $response->headers = $json['headers'];
        return $response;
    }
    #[\Return_Type_Will_Change]
    public function jsonSerialize()
    {
        return ['body' => $this->body, 'headers' => $this->headers];
    }
    private function parse_headers(array $headers): array
    {
        $values = [];
        foreach (array_reverse($headers) as $header) {
            if (preg_match('{^([^:]++):\s*(.+?)\s*$}i', (string) $header, $match)) {
                $values[strtolower($match[1])][] = $match[2];
            } elseif (preg_match('{^HTTP/}i', (string) $header)) {
                break;
            }
        }
        return $values;
    }
}