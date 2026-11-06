<?php

namespace App\Http\Requests;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'court_id' => ['required', 'integer'],
            'sport' => ['nullable', Rule::in(Booking::SPORTS)],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'start_hour' => ['required', 'integer', 'between:0,23'],
            'end_hour' => ['required', 'integer', 'between:1,24', 'gt:start_hour'],
        ];
    }
}
