<?php

namespace Tests\Feature\Auth;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FormAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_auth_forms_link_each_validation_error_to_its_field(): void
    {
        foreach ($this->forms() as [$url, $fields, $action]) {
            $messages = [];
            foreach ($fields as $field) {
                $messages[$field] = "Problem with {$field}";
            }
            $errors = (new ViewErrorBag)->put('default', new MessageBag($messages));
            $response = $this->withSession(['errors' => $errors])->get($url);
            $response->assertOk();
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//form[@method="POST" and @action="'.$action.'"]')->length);

            foreach ($fields as $field) {
                $inputs = $xpath->query('//input[@id="'.$field.'"]');
                $this->assertSame(1, $inputs->length, $url.' '.$field);
                $this->assertSame('true', $inputs->item(0)->getAttribute('aria-invalid'));
                $this->assertSame($field.'-error', $inputs->item(0)->getAttribute('aria-describedby'));
                $errors = $xpath->query('//*[@id="'.$field.'-error"]');
                $this->assertSame(1, $errors->length, $url.' '.$field);
                $this->assertStringContainsString("Problem with {$field}", $errors->item(0)->textContent);
            }
        }
    }

    public function test_auth_forms_do_not_mark_clean_fields_invalid(): void
    {
        foreach ($this->forms() as [$url, $fields, $action]) {
            $response = $this->withSession(['errors' => new ViewErrorBag])->get($url);
            $response->assertOk();
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//form[@method="POST" and @action="'.$action.'"]')->length);
            foreach ($fields as $field) {
                $inputs = $xpath->query('//input[@id="'.$field.'"]');
                $this->assertSame(1, $inputs->length, $url.' '.$field);
                $this->assertFalse($inputs->item(0)->hasAttribute('aria-invalid'));
                $this->assertFalse($inputs->item(0)->hasAttribute('aria-describedby'));
                $this->assertSame(0, $xpath->query('//*[@id="'.$field.'-error"]')->length);
            }
        }
    }

    private function forms(): array
    {
        return [
            ['/login', ['email', 'password'], route('login')],
            ['/register', ['business_name', 'subdomain', 'name', 'email', 'email_code', 'password'], route('register')],
            ['/forgot-password', ['email'], route('password.email')],
            ['/reset-password/test-token?email=owner%40example.test', ['email', 'password'], route('password.store')],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
