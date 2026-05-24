<?php
/**
 * Structured evidence packet for code-task scaffolding.
 *
 * @package DataMachineCode\CodeTask
 */

namespace DataMachineCode\CodeTask;

defined('ABSPATH') || exit;

class EvidencePacket
{

    /**
     * Raw normalized packet data.
     *
     * @var array<string,mixed>
     */
    private array $data;

    /**
     * @param array<string,mixed> $data Normalized packet data.
     */
    private function __construct( array $data )
    {
        $this->data = $data;
    }

    /**
     * Build and validate a packet from decoded JSON input.
     *
     * @param  mixed $packet Decoded packet.
     * @return self|\WP_Error
     */
    public static function from_array( mixed $packet ): self|\WP_Error
    {
        if (! is_array($packet) ) {
            return new \WP_Error('invalid_packet', 'Evidence packet must be a JSON object.', array( 'status' => 400 ));
        }

        $required = array( 'source', 'source_url', 'title', 'summary', 'classification', 'repo' );
        foreach ( $required as $field ) {
            if (! array_key_exists($field, $packet) || self::is_empty_value($packet[ $field ]) ) {
                return new \WP_Error('missing_packet_field', sprintf('Evidence packet is missing required field: %s', $field), array( 'status' => 400 ));
            }
        }

        if (! is_string($packet['source_url']) || ! wp_http_validate_url($packet['source_url']) ) {
            return new \WP_Error('invalid_source_url', 'Evidence packet source_url must be a valid http(s) URL.', array( 'status' => 400 ));
        }

        $classification = self::normalize_string_list($packet['classification'], 'classification');
        if ($classification instanceof \WP_Error ) {
            return $classification;
        }

        $suggested_tests = self::normalize_string_list($packet['suggested_tests'] ?? array(), 'suggested_tests', false);
        if ($suggested_tests instanceof \WP_Error ) {
            return $suggested_tests;
        }

        $constraints = self::normalize_string_list($packet['constraints'] ?? array(), 'constraints', false);
        if ($constraints instanceof \WP_Error ) {
            return $constraints;
        }

        $data = array(
        'source'          => sanitize_text_field((string) $packet['source']),
        'source_url'      => esc_url_raw((string) $packet['source_url']),
        'title'           => sanitize_text_field((string) $packet['title']),
        'summary'         => sanitize_textarea_field((string) $packet['summary']),
        'classification'  => $classification,
        'repo'            => trim((string) $packet['repo']),
        'suggested_tests' => $suggested_tests,
        'constraints'     => $constraints,
        );

        return new self($data);
    }

    /**
     * Return the normalized packet as an array.
     *
     * @return array<string,mixed>
     */
    public function to_array(): array
    {
        return $this->data;
    }

    public function source(): string
    {
        return (string) $this->data['source'];
    }

    public function source_url(): string
    {
        return (string) $this->data['source_url'];
    }

    public function title(): string
    {
        return (string) $this->data['title'];
    }

    public function summary(): string
    {
        return (string) $this->data['summary'];
    }

    public function repo(): string
    {
        return (string) $this->data['repo'];
    }

    /**
     * @return string[]
     */
    public function classification(): array
    {
        return $this->data['classification'];
    }

    /**
     * @return string[]
     */
    public function suggested_tests(): array
    {
        return $this->data['suggested_tests'];
    }

    /**
     * @return string[]
     */
    public function constraints(): array
    {
        return $this->data['constraints'];
    }

    /**
     * @param mixed $value Value to inspect.
     */
    private static function is_empty_value( mixed $value ): bool
    {
        if (is_array($value) ) {
            return array() === $value;
        }

        return '' === trim((string) $value);
    }

    /**
     * Normalize a list of strings.
     *
     * @param  mixed  $value             List value.
     * @param  string $field             Field label.
     * @param  bool   $require_non_empty Whether at least one item is required.
     * @return string[]|\WP_Error
     */
    private static function normalize_string_list( mixed $value, string $field, bool $require_non_empty = true ): array|\WP_Error
    {
        if (! is_array($value) ) {
            return new \WP_Error('invalid_packet_field', sprintf('Evidence packet field %s must be an array of strings.', $field), array( 'status' => 400 ));
        }

        $items = array();
        foreach ( $value as $item ) {
            if (! is_scalar($item) ) {
                return new \WP_Error('invalid_packet_field', sprintf('Evidence packet field %s must contain only strings.', $field), array( 'status' => 400 ));
            }

            $item = trim((string) $item);
            if ('' !== $item ) {
                $items[] = sanitize_text_field($item);
            }
        }

        if ($require_non_empty && array() === $items ) {
            return new \WP_Error('invalid_packet_field', sprintf('Evidence packet field %s must contain at least one string.', $field), array( 'status' => 400 ));
        }

        return $items;
    }
}
