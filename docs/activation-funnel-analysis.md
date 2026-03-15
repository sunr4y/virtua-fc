# Activation Funnel Analysis — VirtuaFC (Last 30 Days)

## Raw Data

| Step | Count | % of Total | Step Drop-off |
|------|-------|-----------|---------------|
| 0. Invite sent | 399 | 100% | — |
| 1. Registered | 250 | 62.7% | -37.3% |
| 2. Game created | 171 | 42.9% | -31.6% |
| 3. Setup completed | 171 | 42.9% | 0% |
| 4. Welcome completed | 171 | 42.9% | 0% |
| 5. Onboarding completed | 170 | 42.6% | -0.6% |
| 6. First match played | 161 | 40.4% | -5.3% |
| 7. Matchday 5 reached | 83 | 20.8% | -48.4% |
| 8. Season completed | 46 | 11.5% | -44.6% |

---

## Key Observations

### What's working well
- **Steps 2→6 (Game created → First match)**: Near-zero drop-off. The welcome tutorial, loading screen, onboarding budget allocation, and first match experience are solid. Only 10 users lost across 4 steps. This is a well-engineered flow.
- **Setup/Welcome/Onboarding**: 171→170→161 shows the guided onboarding is not a friction point at all.

### Three critical gaps (in order of impact)

---

## Gap #1: First Match → Matchday 5 (-48.4%, 78 users lost)

**This is the single biggest problem.** Half the users who play their first match never reach matchday 5. That's only 4 more matchdays — roughly 15-20 minutes of gameplay.

**Root causes identified from codebase analysis:**

1. **No reason to come back**: After the first match, users land on the game dashboard. There's no hook, cliffhanger, or "next thing to do" that creates urgency. The transfer window is open, scouting takes 5-15 matchdays to return results, and the league table is barely started — nothing feels consequential yet.

2. **Repetitive loop too early**: Matchdays 1-5 are: set lineup → advance → watch match → repeat. Without meaningful squad events, transfers, or story beats, it feels like grinding before the game has earned the player's engagement.

3. **38-matchday season feels daunting**: A full season is ~5-6 hours. Users who just wanted to try the game may not realize they can play in short bursts. There's no session-length indicator or "play 5 minutes" framing.

4. **Blocking actions frustrate new users**: Squad cap enforcement and academy evaluations can block matchday advancement at the start of a season. A new user who doesn't understand why they can't advance will leave.

5. **No push notifications or email reminders**: There's no re-engagement mechanism. If a user closes the tab after matchday 1, nothing brings them back.

**Priority: CRITICAL — This is where the funnel bleeds most.**

---

## Gap #2: Invite Sent → Registered (-37.3%, 149 users lost)

**37% of invited users never register.** This is the largest absolute number of lost users.

**Likely causes:**

1. **Invite email quality/timing**: The invite is the first impression. If the email doesn't clearly communicate the value proposition or has a weak CTA, users won't click through.

2. **Registration friction**: The registration form requires an invite code. If users lose the email, can't find the code, or the code expired, they're blocked.

3. **No follow-up**: There appears to be no reminder email for users who received an invite but didn't register. One invite email → if ignored, the user is lost forever.

4. **Audience targeting**: Some invitees may not be football/soccer fans, or may not understand what a football manager game is.

**Priority: HIGH — Large absolute numbers, but partly outside the product (email/marketing).**

---

## Gap #3: Registered → Game Created (-31.6%, 79 users lost)

**31.6% of registered users never create a game.** They signed up but bounced before even starting.

**Likely causes:**

1. **Post-registration dead end**: After registering, users land on `/dashboard`. If the dashboard doesn't immediately and obviously guide them to create a game, they drop off. The team selection page requires users to browse teams and pick one — this is a decision point that causes friction.

2. **Team selection overwhelm**: Users must choose from La Liga or Segunda División teams. For casual fans, this is a meaningful decision with no clear guidance on which team to pick (difficulty, budget, squad quality).

3. **No "just start playing" option**: There's no quick-start or recommended team. Every user must make an informed choice before they can begin.

4. **Account created but game deferred**: Some users may register to "claim their spot" in beta but plan to play later. Without a nudge, "later" becomes "never."

**Priority: HIGH — These users already showed intent by registering.**

---

## Gap #4: Matchday 5 → Season Completed (-44.6%, 37 users lost)

**Almost half the users who reach matchday 5 don't finish the season.** But in absolute terms this is 37 users, smaller than the other gaps.

**Likely causes:**

1. **Season is too long**: 38 league matchdays + cup matches + European competition = potentially 50+ matchdays. At ~5 min each, that's 4+ hours. Many users lose interest mid-season.

2. **Mid-season plateau**: After ~10 matchdays, league positions stabilize. If a user is mid-table with no realistic shot at the title or relegation danger, there's no narrative tension.

3. **No mid-season milestones or rewards**: The game tracks matchday 5 as a milestone but there's nothing between matchday 5 and season end. No "half-season review," no awards, no interim goals.

4. **Transfer window closes**: After the winter window closes (~matchday 19), the only activity is playing matches. Squad management becomes static.

**Priority: MEDIUM — Smaller absolute impact, but important for long-term retention.**

---

## Prioritized Recommendations

### Tier 1: Stop the bleeding (First Match → Matchday 5)

| # | Recommendation | Effort | Impact |
|---|---------------|--------|--------|
| 1 | **Add "next session" hooks after each match**: Show a compelling preview of what's coming (upcoming rival, transfer deadline, scouting result due) to create a reason to play one more matchday | Medium | High |
| 2 | **Seed early engagement events in matchdays 1-4**: Trigger a scouting result, an incoming transfer offer, or a notable news event in the first 3-4 matchdays so users see the game has depth beyond just matches | Medium | High |
| 3 | **Add email/push re-engagement**: Send a "Your next match is waiting" email if a user hasn't played in 48 hours (for users in matchdays 1-10) | Medium | High |
| 4 | **Simplify early matchdays**: Consider auto-setting lineup for matchdays 1-3 and showing a "tip" about lineup management, reducing clicks to just "advance + watch" | Low | Medium |

### Tier 2: Convert registered users (Registered → Game Created)

| # | Recommendation | Effort | Impact |
|---|---------------|--------|--------|
| 5 | **Auto-redirect to team selection after registration**: Skip the dashboard entirely for new users with no games — go straight to team selection | Low | High |
| 6 | **Add "Quick Start" with a recommended team**: Suggest a team based on difficulty level (e.g., "Start with Real Madrid for an easy experience" or "Try Betis for a challenge") | Low | Medium |
| 7 | **Send "You haven't started yet" email**: 24 hours after registration with no game created, send a nudge email | Medium | Medium |

### Tier 3: Improve invite conversion (Invite → Registered)

| # | Recommendation | Effort | Impact |
|---|---------------|--------|--------|
| 8 | **A/B test invite email copy**: Test different subject lines, CTAs, and value propositions | Low | Medium |
| 9 | **Send a reminder email**: 3 days after invite if not registered, send a follow-up | Medium | Medium |
| 10 | **Extend invite code expiry**: If codes expire too quickly, extend the window | Low | Low |

### Tier 4: Season completion (Matchday 5 → Season End)

| # | Recommendation | Effort | Impact |
|---|---------------|--------|--------|
| 11 | **Add mid-season milestones**: Half-season awards, winter break recap, monthly best XI — give users narrative beats and acknowledgment | Medium | Medium |
| 12 | **Dynamic season goals**: If user is mid-table, adjust messaging to "can you finish top 6 for European qualification?" instead of just title/relegation | Medium | Medium |
| 13 | **"Sim to end" option**: For users who want to see outcomes but don't want to play 20 more matchdays, offer a fast-forward option | High | Medium |

---

## Summary

The funnel's biggest lever is **matchday 1→5 retention**. The onboarding flow (steps 2-6) is excellent — don't touch it. Focus engineering effort on:

1. **Making the first 5 matchdays more eventful** (seeded engagement events)
2. **Creating re-engagement hooks** (post-match previews, email nudges)
3. **Reducing friction for new registrations** (auto-redirect to team selection, quick start)
4. **Invite email optimization** (follow-ups, better copy)

The conversion from invite to first match is 64.4% (161/250 registered users) which is reasonable for a beta. The critical failure is that **only 51.6% of first-match players reach matchday 5** — this is where the product needs the most attention.
