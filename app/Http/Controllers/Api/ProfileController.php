<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show()
    {
        return response()->json([
            'user' => Auth::user(),
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'first_name' => 'required|string|max:255',
            'paternal_last_name' => 'required|string|max:255',
            'maternal_last_name' => 'nullable|string|max:255',
            'email'  => 'required|email|unique:users,email,' . $user->id,
            'phone'  => 'nullable|string|max:20',
            'avatar' => 'nullable|image|max:5120',
            'theme'  => 'nullable|in:light,dark,system',
            'ui_density' => 'nullable|in:normal,compact',
            'sidebar_state' => 'nullable|in:expanded,collapsed',
            'sidebar_hover_preview' => 'nullable|boolean',
            'sidebar_position' => 'nullable|in:left,right',
            'locale' => 'nullable|in:es,en,ja,de,zh,fr',
            'availability' => 'nullable|in:available,busy,disconnected',
        ]);

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            if (!$file || !$file->isValid()) {
                $errorCode = $file?->getError();
                $message = __('api.avatar_failed');
                if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
                    $message = 'La imagen supera el límite del servidor. Ajusta upload_max_filesize y post_max_size.';
                }
                return response()->json([
                    'message' => $message,
                ], 422);
            }
            if (is_string($user->avatar_path) && trim($user->avatar_path) !== '') {
                try {
                    Storage::disk('public')->delete($user->avatar_path);
                } catch (\Throwable $e) {
                    // Si falla borrar el anterior, continuamos con el nuevo
                }
            }
            // Guardar por contenido para evitar "Path must not be empty" cuando la ruta temporal no está disponible (p. ej. Windows/Laragon)
            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = 'avatar_' . uniqid('', true) . '.' . strtolower($extension);
            $path = 'avatars/' . $filename;
            try {
                $contents = $file->get();
                if ($contents === false || $contents === '') {
                    return response()->json(['message' => __('api.avatar_failed')], 422);
                }
                if (!Storage::disk('public')->put($path, $contents)) {
                    return response()->json(['message' => __('api.avatar_failed')], 422);
                }
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => __('api.avatar_failed'),
                ], 422);
            }
            $user->avatar_path = $path;
        }

        $user->first_name = $request->first_name;
        $user->paternal_last_name = $request->paternal_last_name;
        $user->maternal_last_name = $request->maternal_last_name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        if ($request->filled('theme')) {
            $user->theme = $request->theme;
        }
        if ($request->filled('ui_density')) {
            $user->ui_density = $request->ui_density;
        }
        if ($request->filled('sidebar_state')) {
            $user->sidebar_state = $request->sidebar_state;
        }
        if ($request->has('sidebar_hover_preview')) {
            $user->sidebar_hover_preview = (bool) $request->sidebar_hover_preview;
        }
        if ($request->filled('sidebar_position')) {
            $user->sidebar_position = $request->sidebar_position;
        }
        if ($request->filled('locale')) {
            $user->locale = $request->locale;
        }
        if ($request->filled('availability')) {
            $user->availability = $request->availability;
        }

        $user->saveQuietly();

        return response()->json([
            'message' => __('api.profile_updated'),
            'user'    => $user->fresh(),
        ]);
    }

    public function updateTheme(Request $request)
    {
        $data = $request->validate([
            'theme' => ['required', 'in:light,dark,system'],
        ]);

        $user = Auth::user();
        $user->theme = $data['theme'];
        $user->saveQuietly();

        return response()->json([
            'message' => __('api.theme_updated'),
            'theme'   => $user->theme,
        ]);
    }

    public function updateDensity(Request $request)
    {
        $data = $request->validate([
            'ui_density' => ['required', 'in:normal,compact'],
        ]);

        $user = Auth::user();
        $user->ui_density = $data['ui_density'];
        $user->saveQuietly();

        return response()->json([
            'message' => __('api.density_updated'),
            'ui_density' => $user->ui_density,
        ]);
    }

    public function updateSidebar(Request $request)
    {
        $data = $request->validate([
            'sidebar_state' => ['required', 'in:expanded,collapsed'],
            'sidebar_hover_preview' => ['required', 'boolean'],
        ]);

        $user = Auth::user();
        $user->sidebar_state = $data['sidebar_state'];
        $user->sidebar_hover_preview = $data['sidebar_hover_preview'];
        $user->saveQuietly();

        return response()->json([
            'message' => __('api.sidebar_updated'),
            'sidebar_state' => $user->sidebar_state,
            'sidebar_hover_preview' => $user->sidebar_hover_preview,
        ]);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'theme' => 'nullable|in:light,dark,system',
            'theme_color' => 'nullable|in:zinc,rose,blue,green,violet,orange',
            'ui_density' => 'nullable|in:normal,compact',
            'sidebar_state' => 'nullable|in:expanded,collapsed',
            'sidebar_hover_preview' => 'nullable|boolean',
            'sidebar_position' => 'nullable|in:left,right',
            'locale' => 'nullable|in:es,en,ja,de,zh,fr',
            'availability' => 'nullable|in:available,busy,disconnected',
        ]);

        $user = Auth::user();

        if (array_key_exists('theme', $data) && $data['theme'] !== null) {
            $user->theme = $data['theme'];
        }
        if (array_key_exists('theme_color', $data) && $data['theme_color'] !== null) {
            $user->theme_color = $data['theme_color'];
        }
        if (array_key_exists('ui_density', $data) && $data['ui_density'] !== null) {
            $user->ui_density = $data['ui_density'];
        }
        if (array_key_exists('sidebar_state', $data) && $data['sidebar_state'] !== null) {
            $user->sidebar_state = $data['sidebar_state'];
        }
        if (array_key_exists('sidebar_hover_preview', $data)) {
            $user->sidebar_hover_preview = (bool) $data['sidebar_hover_preview'];
        }
        if (array_key_exists('sidebar_position', $data) && $data['sidebar_position'] !== null) {
            $user->sidebar_position = $data['sidebar_position'];
        }
        if (array_key_exists('locale', $data) && $data['locale'] !== null) {
            $user->locale = $data['locale'];
        }
        if (array_key_exists('availability', $data) && $data['availability'] !== null) {
            $user->availability = $data['availability'];
        }

        $user->saveQuietly();

        return response()->json([
            'message' => __('api.preferences_updated'),
            'user' => $user->fresh(),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => [
                'required',
                'string',
                'min:12',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
                'confirmed',
            ],
        ]);

        $user = Auth::user();

        if (!\Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => __('api.password_incorrect'),
            ], 422);
        }

        if (\Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'La nueva contraseña no puede ser igual a la actual.',
            ], 422);
        }

        $user->password = \Hash::make($request->password);
        $user->force_password_change = false;
        $user->saveQuietly();

        return response()->json([
            'message' => __('api.password_updated'),
        ]);
    }
}
