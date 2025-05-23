<?php

use Livewire\Volt\Volt;
use App\Models\Challenge;

use App\Http\Controllers\CourseController;
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\ScheduleController;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;




Route::get('/challenge/{challenge}', function (Challenge $challenge) {
    return view('challenge', ['challenge' => $challenge]);
})->name('challenge');

use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::get('/', function () {
    $challenges = \App\Models\Challenge::orderBy('id')->take(3)->get();
    return view('welcome', ['challenges' => $challenges]);
})->name('home');



Route::middleware(
    array_filter([
        'auth',
        env('USE_WORKOS') ? ValidateSessionWithWorkOS::class : null,
    ])
)->group(function () {

    Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('learning', [\App\Http\Controllers\LearningController::class, 'index'])->name('learning');
    Route::get('challenge/{challenge}', [\App\Http\Controllers\LearningController::class, 'show'])->name('challenge');
    Route::get('/challenges/{challenge}/tasks/{task}', [ChallengeController::class, 'showTask'])
        ->name('challenge.task')
        ->middleware(['auth', \App\Http\Middleware\CheckTaskCompletion::class]);
    Route::view('notifications', 'notifications')->name('notifications');
    Route::get('courses', [CourseController::class, 'index'])->name('courses');
    Route::get('learning-materials', [\App\Http\Controllers\LearningMaterialController::class, 'index'])->name('learning-materials');
    Route::get('assignments', [\App\Http\Controllers\AssignmentController::class, 'index'])->name('assignments');
    Route::get('profile', [\App\Http\Controllers\ProfileController::class, 'index'])->name('profile');
    Route::get('feedback', [\App\Http\Controllers\FeedbackController::class, 'index'])->name('feedback');
    Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule');
    Route::view('grades','grades')->name('grades');
    Route::view('messages','messages')->name('messages');
    // Forum routes
    Route::get('forums', [ForumController::class, 'index'])->name('forums');
    Route::get('forums/create', [ForumController::class, 'createTopic'])->name('forum.create-topic');
    Route::post('forums/create', [ForumController::class, 'storeTopic'])->name('forum.store-topic');
    Route::get('forums/search', [ForumController::class, 'search'])->name('forum.search');
    Route::get('forums/{category}', [ForumController::class, 'category'])->name('forum.category');
    Route::get('forums/{category}/create', [ForumController::class, 'createTopic'])->name('forum.category.create-topic');
    Route::get('forums/{category}/{topic}', [ForumController::class, 'topic'])->name('forum.topic');
    Route::post('forums/comment/{topic}', [ForumController::class, 'storeComment'])->name('forum.store-comment')->where('topic', '[0-9]+');
    Route::post('forums/like', [ForumController::class, 'like'])->name('forum.like');
    Route::view('help-center', 'help-center')->name('help-center');
    Route::view('technical-support', 'technical-support')->name('technical-support');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

// Add this debug route temporarily
Route::get('/debug-task-status/{task}', function (\App\Models\Task $task) {
    $isCompleted = \App\Models\StudentAnswer::where('user_id', Auth::id())
        ->where('task_id', $task->id)
        ->where('is_correct', true)
        ->exists();

    return response()->json([
        'task_id' => $task->id,
        'user_id' => Auth::id(),
        'is_completed' => $isCompleted,
        'answers' => \App\Models\StudentAnswer::where('user_id', Auth::id())
            ->where('task_id', $task->id)
            ->get()
    ]);
})->middleware('auth');

require __DIR__.'/auth.php';
