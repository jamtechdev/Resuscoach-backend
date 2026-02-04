<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    /**
     * Submit contact form.
     */
    public function submit(ContactRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // Save to database
            Contact::create($data);

            // Log the contact submission
            Log::info('Contact form submission', [
                'name' => $data['name'],
                'email' => $data['email'],
                'subject' => $data['subject'],
            ]);

            // TODO: Add email notification here if needed
            // Example: Mail::to(config('mail.from.address'))->send(new ContactFormMail($data));

            return response()->json([
                'success' => true,
                'message' => 'Contact form submitted successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Contact form submission failed', [
                'error' => $e->getMessage(),
                'data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit contact form. Please try again.',
            ], 500);
        }
    }
}
