<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use LevelUp\Experience\Models\Achievement;
use LevelUp\Experience\Concerns\GiveExperience;
use LevelUp\Experience\Concerns\HasAchievements;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;
    use GiveExperience;
    use HasAchievements;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        "name",
        "email",
        "password",
        "points",
        "workos_id",
        "avatar",
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ["password", "remember_token"];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "email_verified_at" => "datetime",
            "password" => "hashed",
        ];
    }

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->avatar)) {
                // Use a placeholder avatar URL if none is provided
                // Example using ui-avatars.com which generates based on name
                // $user->avatar = 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=random&color=fff';
                // Or a static placeholder
                 $user->avatar = 'https://via.placeholder.com/150/771796'; // Example placeholder
            }
        });
    }


    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(" ")
            ->map(fn(string $name) => Str::of($name)->substr(0, 1))
            ->implode("");
    }

    /**
     * Get the badges earned by the user.
     */
    public function badges()
    {
        return $this->belongsToMany(Badge::class, "user_badges")
            ->withPivot("earned_at", "is_pinned", "is_showcased")
            ->withTimestamps();
    }

    /**
     * Get the tasks assigned to the user.
     */
    public function tasks()
    {
        return $this->belongsToMany(Task::class, "user_tasks")
            ->withPivot(
                "progress",
                "completed",
                "completed_at",
                "reward_claimed",
                "reward_claimed_at"
            )
            ->withTimestamps();
    }

    /**
     * Get the challenges the user is participating in.
     */
    public function challenges()
    {
        return $this->belongsToMany(Challenge::class, "user_challenges")
            ->withPivot(
                "status",
                "progress",
                "completed_at",
                "reward_claimed",
                "reward_claimed_at",
                "attempts"
            )
            ->withTimestamps();
    }
    /**
     * Enroll the user in a challenge.
     */
    public function enrollInChallenge(Challenge $challenge): void
    {
        // Check if user is already enrolled
        if (
            $this->challenges()->where("challenge_id", $challenge->id)->exists()
        ) {
            return;
        }

        // Check if user meets the level requirement
        if ($this->getLevel() < $challenge->required_level) {
            throw new \Exception(
                "You need to be at least level {$challenge->required_level} to join this challenge."
            );
        }

        // Check if challenge has reached maximum participants
        if (
            $challenge->max_participants &&
            $challenge->users()->count() >= $challenge->max_participants
        ) {
            throw new \Exception(
                "This challenge has reached its maximum number of participants."
            );
        }

        // Enroll the user
        $this->challenges()->attach($challenge->id, [
            "status" => "enrolled",
            "progress" => 0,
            "attempts" => 1,
        ]);

        // Fire event if needed
        // event(new UserEnrolledInChallenge($this, $challenge));
    }

    /**
     * Update the user's progress in a challenge.
     */
    public function updateChallengeProgress(
        Challenge $challenge,
        int $progress
    ): void {
        $userChallenge = $this->challenges()
            ->where("challenge_id", $challenge->id)
            ->first();

        if (!$userChallenge) {
            throw new \Exception("User is not enrolled in this challenge.");
        }

        // Ensure progress doesn't exceed 100%
        $progress = min(100, max(0, $progress));

        // Update the progress
        $this->challenges()->updateExistingPivot($challenge->id, [
            "progress" => $progress,
            "status" => $progress >= 100 ? "completed" : "in_progress",
            "completed_at" => $progress >= 100 ? now() : null,
        ]);

        // If challenge is completed, we could award points, badges, etc.
        if ($progress >= 100 && $userChallenge->pivot->status !== "completed") {
            $this->completedChallenge($challenge);
        }
    }

    /**
     * Handle challenge completion and rewards.
     */
    protected function completedChallenge(Challenge $challenge): void
    {
        // Award points
        if ($challenge->points_reward > 0) {
            $this->addPoints(
                $challenge->points_reward,
                reason: "Completed challenge: {$challenge->name}"
            );
        }

        // Award badges if any
        foreach ($challenge->badges as $badge) {
            $this->badges()->syncWithoutDetaching([
                $badge->id => [
                    "earned_at" => now(),
                ],
            ]);
        }

        // Award achievements if any
        foreach ($challenge->achievements as $achievement) {
            $this->grantAchievement($achievement);
        }

        // Process any additional rewards
        if ($challenge->additional_rewards) {
            // Handle custom additional rewards based on the structure you defined
            // For example, if you have virtual currency rewards:
            if (isset($challenge->additional_rewards["currency"])) {
                // Add currency to user
                // $this->addCurrency($challenge->additional_rewards['currency']);
            }
        }

        // Fire event if needed
        // event(new UserCompletedChallenge($this, $challenge));
    }

    /**
     * Claim rewards for a completed challenge.
     */
    public function claimChallengeRewards(Challenge $challenge): void
    {
        $userChallenge = $this->challenges()
            ->where("challenge_id", $challenge->id)
            ->first();

        if (!$userChallenge) {
            throw new \Exception("User is not enrolled in this challenge.");
        }

        if ($userChallenge->pivot->status !== "completed") {
            throw new \Exception(
                "Challenge must be completed before claiming rewards."
            );
        }

        if ($userChallenge->pivot->reward_claimed) {
            throw new \Exception(
                "Rewards for this challenge have already been claimed."
            );
        }

        // Update the user challenge record
        $this->challenges()->updateExistingPivot($challenge->id, [
            "reward_claimed" => true,
            "reward_claimed_at" => now(),
        ]);

        // Fire event if needed
        // event(new UserClaimedChallengeRewards($this, $challenge));
    }

    /**
     * Get the user's active challenges.
     */
    public function getActiveChallenges()
    {
        return $this->challenges()
            ->wherePivot("status", "!=", "completed")
            ->wherePivot("status", "!=", "failed")
            ->where("is_active", true)
            ->where(function ($query) {
                $query->whereNull("end_date")->orWhere("end_date", ">=", now());
            })
            ->get();
    }

    /**
     * Get the user's completed challenges.
     */
    public function getCompletedChallenges()
    {
        return $this->challenges()->wherePivot("status", "completed")->get();
    }

    /**
     * Check if user has available challenges they can join.
     */
    public function getAvailableChallenges()
    {
        $userLevel = $this->getLevel();
        $currentChallengeIds = $this->challenges()
            ->pluck("challenge_id")
            ->toArray();

        return Challenge::where("is_active", true)
            ->where("required_level", "<=", $userLevel)
            ->where(function ($query) {
                $query->whereNull("end_date")->orWhere("end_date", ">=", now());
            })
            ->where(function ($query) {
                // Either no max_participants or hasn't reached the limit
                $query
                    ->whereNull("max_participants")
                    ->orWhereRaw(
                        "(SELECT COUNT(*) FROM user_challenges WHERE user_challenges.challenge_id = challenges.id) < challenges.max_participants"
                    );
            })
            ->whereNotIn("id", $currentChallengeIds)
            ->get();
    }
    public function studentAnswers()
    {
        return $this->hasMany(StudentAnswer::class);
    }

    /**
     * Get the forum topics created by the user.
     */
    public function forumTopics()
    {
        return $this->hasMany(ForumTopic::class);
    }

    /**
     * Get the forum comments created by the user.
     */
    public function forumComments()
    {
        return $this->hasMany(ForumComment::class);
    }

    /**
     * Get the forum likes created by the user.
     */
    public function forumLikes()
    {
        return $this->hasMany(ForumLike::class);
    }

    /**
     * Get the achievements earned by the user.
     */
    public function achievements()
    {
        $relation = $this->belongsToMany(\LevelUp\Experience\Models\Achievement::class, 'achievement_user');

        // Check if the columns exist in the database before adding them to the pivot
        try {
            $relation->withPivot('unlocked_at', 'progress');
        } catch (\Exception $e) {
            // If the columns don't exist, just continue without them
            \Illuminate\Support\Facades\Log::warning('Achievement pivot columns not found: ' . $e->getMessage());
        }

        return $relation->withTimestamps();
    }
}
