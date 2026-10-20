<?php

declare(strict_types=1);

return [
    /*
     | When a task reminds its people. Days before the due date, days after it (the first overdue reminders), how often an
     | overdue task keeps reminding, and how long a review or a wait may stay quiet before it is mentioned.
     */
    'reminders' => [
        'due_offsets' => array_map('intval', explode(',', (string) env('TASK_REMINDER_DUE_DAYS', '10,5,2,0'))),
        'overdue_offsets' => array_map('intval', explode(',', (string) env('TASK_REMINDER_OVERDUE_DAYS', '1,3,7'))),
        'overdue_repeat_days' => (int) env('TASK_REMINDER_OVERDUE_REPEAT_DAYS', 7),
        'review_after_days' => (int) env('TASK_REMINDER_REVIEW_AFTER_DAYS', 2),
        'waiting_after_days' => (int) env('TASK_REMINDER_WAITING_AFTER_DAYS', 3),
    ],
];
