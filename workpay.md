# CSV Import & Feedback System (Senior Backend Engineer Assessment)

## Scenario

Workpay customers often need to bulk-upload employees into the system from CSV files. Your
task is to design and implement a backend feature that:

1. Accepts an uploaded CSV of employees.
2. Imports the data into a database reliably and at scale.
3. Provides clear feedback on progress and errors while the import runs.
The solution should demonstrate not just coding skills, but also architecture, trade-offs, and
communication of decisions.

## Core Goals

```
● Handle large CSVs (thousands of rows) efficiently.
● Validate data row-by-row, and skip/record invalid rows instead of blocking the whole
import.
● Provide a way for the user (through a user interface) to see import progress in near
real-time.
● Ensure the process is idempotent: safe to retry without corrupting data or creating
duplicates.
```
## Expectations

You should decide and implement:
● Schema design: how you’ll represent the data that will be used in the entire import
cycle/journey.
● Validation rules: what makes a row acceptable or not.
● Error handling: how invalid rows are tracked and surfaced.
● Progress tracking: how the client can know where the import stands.
● Performance approach: how you avoid running out of memory or blocking on big files.
● Safety measures: how you guarantee retries won’t double-insert.

## Deliverables

```
● A working implementation using only Laravel and livewire tech stack.
● A README with instructions to run the solution and test it with sample CSVs provided.
● A short DECISIONS.md explaining your key choices:
○ How you designed the schema.
○ How you approached validation, errors, and idempotency.
○ How you approached progress reporting.
○ Any trade-offs you made (e.g., simplicity vs scalability).
● Proof of AI usage in development : this can include chat histories, AI CLI session logs,
or screenshots demonstrating how AI was used during the build. These will be requested
for display/demo during the assessment.
● Some automated tests showing your solution works under both normal and error
conditions (optional but preferred).
```
## Use of AI Tools

We encourage you to use AI tools (e.g., Copilot, ChatGPT, Cursor, etc.) as part of this
assessment just as you might in your day-to-day engineering work. What matters is how
effectively you use these tools, and whether you can explain and take ownership of the final
solution.
Guidelines:
● Transparency: Please keep a short log of your AI usage. This could be a text file or
screenshots showing some of the prompts you used and the outputs you got. Keep track
of as much of your interactions as possible (download chats, take screenshots of chats
...etc).
● Demonstration: Be ready, in the review, to walk us through:
○ Which AI tools you had set up locally or used to code.
○ A few of the prompts you gave the AI, and how you refined them.
○ Which tasks you handed off to AI.
● Judgment: We care most about how you worked with AI, where you trusted it, where you
intervened, and how you ensured the final solution is correct, reliable, and maintainable.

## House Keeping & Assumptions you can make

### 1. Handling Existing Employees

```
● employee_number and email should be unique i.e no two employees should have
either the same email or employee_number.
● If an import row matches an existing employee: Update their record (idempotent upsert)
→ do not create duplicates.
● If there are multiple conflicting rows for the same employee in one file, take the last
occurrence and mark the earlier ones as skipped with an error message.
```
### 2. Invalid Rows

```
● Rows with validation errors (missing fields, bad email, negative salary, invalid currency
code, malformed dates, etc.) should be skipped, not block the import.
● All invalid rows must be recorded and a way to retrieve the reasons for failure or
skippage should be availed.
```

### Demo

```
● It would be advisable (not required) to have a way of clearing data in your DB in case
you want to demo different journeys i.e happy path & error path.
● Your demo should be working before the presentation.
● You can demo your API without needing a custom UI but you are welcome to build a UI if
you’d want to do so. For this exercise, an API client is sufficient if you don’t want to build
a custom UI.
```
### 3. Duplicates Within a File

```
● Detect and skip duplicates.
● First valid occurrence is processed, subsequent ones should be processed and any
previous occurrence should be logged as “duplicate” and therefore skipped.
```
### 4. Partial Progress & Reliability

```
● An import must be able to resume safely if workers crash or a job retries.
● Retried rows must not double-insert.
```
### 5. Feedback & Progress

```
● Progress counters are based on rows seen, not just successful inserts.
● Percent = (processed_rows / total_rows) * 100.
```
### 7. Security & Scope

```
● Assume single-tenant (no per-company scoping).
● No authentication required for this task (keep it simple).
● File uploads should be validated (size, type), but no need for virus scanning or S
integration.
● Max size: e.g. 50k rows / 20 MB.
● Any deviation (wrong header) → fail fast with a clear error.
```
## Sample Data

We’ll provide:
● A small CSV (~20 rows) with intentionally bad data.
● A large CSV (~20k rows) that’s clean, to test performance.

## Evaluation Rubric

We’re looking for signal across:
● Correctness & Reliability: Does it import valid data, skip invalid, and handle retries
safely?
● Performance: Does it scale to large files without crashing or bogging down?
● Architecture: Are the responsibilities well-structured?


● Error Handling & Feedback: Are errors clear, can progress be tracked, is there a way to
communicate to the user on skipped or failed records?
● Testing: Are there automated tests covering both success and failure paths? (optional
but preferred)
● Communication: Does the README.md and DECISIONS.md file clearly explain what
was built and why? Are you able to communicate the decisions you made and their
rationale?
● AI Usage & Judgment: Are you able to use AI effectively, show your setup, workflow,
and demonstrate ownership of the solution?


