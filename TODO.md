# Subtask Merging Implementation

## Current State Analysis
- Subtasks stored as JSON array with 'text', 'assigned', 'completed' fields
- 'assigned' field is string (single user)
- Display shows separate subtasks even with same text

## Required Changes

### 1. Modify Subtask Structure
- Change 'assigned' from string to array of users
- Each user entry should have 'username' and 'completed' status

### 2. Update Display Logic
- Group subtasks by text
- For same text, merge users with different completion status
- Show all users in one subtask display

### 3. Update Saving Logic
- Handle multiple users per subtask when saving
- Maintain backward compatibility with existing data

### 4. Add Redirect
- After saving changes, redirect to task_detail.php

## Implementation Steps

### Step 1: Update Subtask Structure in tasks.php
- Modify add_subtask action to support multiple users
- Update subtask JSON structure

### Step 2: Update Display Logic in tasks.php
- Modify subtask display to group by text
- Show merged users in single subtask

### Step 3: Update edit_task.php
- Modify subtask handling to support new structure
- Update save logic

### Step 4: Add Redirect Logic
- Ensure redirect to task_detail.php after save

### Step 5: Test Implementation
- Test subtask merging
- Test redirect functionality
