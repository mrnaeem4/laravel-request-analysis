<?php

namespace Sample\App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController
{
    public function index()
    {
        return view('home.index');
    }

    public function post(Request $request)
    {
        $payload = $request->json()->all() ?: $request->all();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Post received',
            'payload' => [
                'name'     => $payload['name'] ?? null,
                'email'    => $payload['email'] ?? null,
                'password' => $payload['password'] ?? null,
            ],
        ]);
    }

    public function upload(Request $request)
    {
        $title  = $request->input('title');
        $errors = [];
        $saved  = [];

        foreach ($request->allFiles() as $field => $file) {
            $items = is_array($file) ? $file : [$file];
            foreach ($items as $item) {
                if (! $item->isValid()) {
                    $errors[] = [
                        'field' => $field,
                        'error' => $item->getErrorMessage(),
                    ];
                    continue;
                }

                $name = $item->store('uploads', 'local');

                $saved[] = [
                    'field' => $field,
                    'saved' => $name,
                    'name'  => $item->getClientOriginalName(),
                    'size'  => $item->getSize(),
                    'type'  => $item->getMimeType(),
                    'ext'   => $item->getClientOriginalExtension(),
                ];
            }
        }

        return response()->json([
            'status'     => $errors === [] ? 'ok' : 'partial',
            'title'      => $title,
            'saved'      => $saved,
            'errors'     => $errors,
            'file_count' => count($saved) + count($errors),
        ]);
    }
}
