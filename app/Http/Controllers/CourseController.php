<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    // ================= API METHODS =================

    // Get all courses
    public function index()
    {
        return response()->json([
            'success' => true,
            'courses' => Course::all()
        ]);
    }

    // Add a new course
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
        ]);

        $course = Course::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Course added successfully',
            'course' => $course
        ], 201);
    }

    // Get a single course
    public function show($id)
    {
        $course = Course::findOrFail($id);

        return response()->json([
            'success' => true,
            'course' => $course
        ]);
    }

    // Update course
    public function update(Request $request, $id)
    {
        $course = Course::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'price' => 'sometimes|required|numeric|min:0',
        ]);

        $course->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Course updated successfully',
            'course' => $course
        ]);
    }

    // Delete course
    public function destroy($id)
    {
        $course = Course::findOrFail($id);
        $course->delete();

        return response()->json([
            'success' => true,
            'message' => 'Course deleted successfully'
        ]);
    }


    // ================= BLADE METHODS =================

    // Display all courses
    public function bladeIndex()
    {
        $courses = Course::query()
            ->withExists([
                'enrollments as enrolled_by_current_user' => fn ($query) =>
                    $query->where('user_id', auth()->id()),
            ])
            ->get();

        return view('courses.index', compact('courses'));
    }

    // Show add course form
    public function bladeCreate()
    {
        return view('courses.create');
    }

    // Save course from Blade form
    public function bladeStore(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
        ]);

        Course::create($validated);

        return redirect()->route('courses.index')
            ->with('success', 'Course added successfully!');
    }

    // Show edit course form
    public function bladeEdit($id)
    {
        $course = Course::findOrFail($id);

        return view('courses.edit', compact('course'));
    }

    // Update course from Blade form
    public function bladeUpdate(Request $request, $id)
    {
        $course = Course::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
        ]);

        $course->update($validated);

        return redirect()->route('courses.index')
            ->with('success', 'Course updated successfully!');
    }

    // Delete course from Blade
    public function bladeDestroy($id)
    {
        $course = Course::findOrFail($id);
        $course->delete();

        return redirect()->route('courses.index')
            ->with('success', 'Course deleted successfully!');
    }
}
