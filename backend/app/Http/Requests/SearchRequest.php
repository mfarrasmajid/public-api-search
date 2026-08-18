<?php

namespace App\Http\Requests;

use App\Services\Search\SearchQueryData;
use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'auth' => ['nullable', 'string', 'in:none,apiKey,OAuth,bearer,unknown'],
            'https' => ['nullable', 'boolean'],
            'cors' => ['nullable', 'string', 'in:yes,no,unknown'],
            'country' => ['nullable', 'string', 'max:100'],
            'openapi' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort' => ['nullable', 'string', 'in:relevance,quality,name,updated'],
        ];
    }

    public function toSearchQuery(): SearchQueryData
    {
        return new SearchQueryData(
            query: (string) $this->string('q'),
            category: $this->input('category'),
            authentication: $this->input('auth'),
            https: $this->has('https') ? $this->boolean('https') : null,
            cors: $this->input('cors'),
            country: $this->input('country'),
            hasOpenapi: $this->has('openapi') ? $this->boolean('openapi') : null,
            page: (int) ($this->input('page') ?? 1),
            perPage: (int) ($this->input('per_page') ?? 20),
            sort: (string) ($this->input('sort') ?? 'relevance'),
        );
    }
}
