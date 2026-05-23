@block @block_feedback_tracker
Feature: Basic tests for Feedback Flow

  @javascript
  Scenario: Plugin block_feedback_tracker appears in the list of installed additional plugins
    Given I log in as "admin"
    When I navigate to "Plugins > Plugins overview" in site administration
    And I follow "Additional plugins"
    Then I should see "Feedback Flow"
    And I should see "block_feedback_tracker"
