@block @block_feedback_tracker @javascript
Feature: Responsiveness block renders on a course page
  In order to monitor my grading turnaround
  As a teacher
  I need the Feedback Flow block to appear on my course page

  Background:
    Given the following "courses" exist:
      | fullname  | shortname |
      | Course 1  | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: Adding the block to a course page renders the empty-state card
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I add the "Feedback Flow" block
    Then I should see "Feedback Flow"
    And I should see "No data yet for this course."
