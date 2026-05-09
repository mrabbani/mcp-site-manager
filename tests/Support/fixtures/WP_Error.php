<?php
class WP_Error
{
    public array $errors = [];
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', $data = '')
    {
        if ($code) {
            $this->errors[$code] = [$message];
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }
    public function get_error_code() { return array_key_first($this->errors) ?: ''; }
    public function get_error_message() { $c = $this->get_error_code(); return $this->errors[$c][0] ?? ''; }
    public function get_error_data($code = '') { $code = $code ?: $this->get_error_code(); return $this->error_data[$code] ?? null; }
    public function has_errors(): bool { return !empty($this->errors); }
}
