<?php
/**
 * Plugin Name: Employee Feedback Form
 * Description: A simple plugin to collect employee feedback and display it in the admin dashboard.
 * Version: 1.0.3
 * Date: 2024-01-26
 * Author: PacificDev
 * Author URI: https://www.pacificdev.com/
 * License: MIT
 * Depends: PHP 7.0, WordPress 5.0
 * Developer: Jenny Martinez
 * Developer URI: http://github.com/lajennylove/
 * 
**/

if ( !class_exists('EmployeeFeedbackForm' ) ) {
    class EmployeeFeedbackForm {
        public function __construct() 
        {
            // Start a session
            session_start();

            // Check if Contact Form 7 is installed and activated
            if ( class_exists('WPCF7' ) ) {
                // Add the shortcode for the feedback form
                add_shortcode( 'employee_feedback_form', array( $this, 'renderFeedbackForm' ) );

                // Create a menu item in the admin dashboard
                add_action( 'admin_menu', array( $this, 'addMenuPage' ) );

                // Register a hook to handle form submission
                add_action( 'admin_post_submit_employee_feedback', array( $this, 'handleFormSubmission' ) );
                add_action( 'admin_post_nopriv_submit_employee_feedback', array( $this, 'handleFormSubmission' ) );

                // Enqueue JavaScript
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );

                // Handle AJAX request to delete feedback
                add_action( 'wp_ajax_delete_employee_feedback', array( $this, 'deleteEmployeeFeedback' ) );
                add_action( 'wp_ajax_nopriv_delete_employee_feedback', array( $this, 'deleteEmployeeFeedback' ) );

                // Activate/deactivate hooks
                register_activation_hook( __FILE__, array( $this, 'activatePlugin' ) );
                register_deactivation_hook( __FILE__, array( $this, 'deactivatePlugin' ) );
                register_uninstall_hook( __FILE__, array( $this, 'uninstallPlugin' ) );
            }
            else {
                // Contact Form 7 is not installed, display a message
                add_action( 'admin_notices', array( $this, 'cf7NotInstalledMessage' ) );
            }
        }

        /**
         * Render the feedback form
         *
         * @return string
         */
        public function renderFeedbackForm(): string
        {
            ob_start(); ?>
            <style>
            .updated {
                background: var(--wp--preset--color--vivid-green-cyan);
                color: white;
                padding: 20px 10px 1px 10px;
                margin-top: 30px;
                font-weight: bold;
                line-height: 21px;
            }
            tt{
                color: var(--wp--preset--color--luminous-vivid-amber);
                size: 12px;
                
            }
            </style>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="submit_employee_feedback">
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>">

                <label for="name">Name:</label>
                <input type="text" name="name" required><br>

                <label for="yesterdays_tasks">Yesterday's Tasks:</label>
                <textarea name="yesterdays_tasks" required></textarea><br>

                <label for="todays_tasks">Today's Tasks:</label>
                <textarea name="todays_tasks" required></textarea><br>

                <label for="blockers">Blockers:</label>
                <textarea name="blockers"></textarea><br><br>

                <input type="submit" name="submit_feedback" value="Submit">
            </form>

            <?php

            // Check if a success message is set in the session
            if ( isset( $_SESSION['feedback_success'] ) ) {
                echo '<div class="updated"><p>' . esc_html($_SESSION['feedback_success']) . '</p></div>';
                // Clear the success message from the session to prevent it from showing again on page refresh
                unset( $_SESSION['feedback_success'] );
            }

            if ( isset( $_SESSION['matter_most'] ) ) {
                echo '<div class="updated"><p>' . esc_html($_SESSION['matter_most']) . '</p></div>';
                unset( $_SESSION['matter_most'] );
            }
            return ob_get_clean();
        }

        /**
         * Handle form submission
         *
         * @return void
         */
        public function handleFormSubmission(): void
        {
            if ( isset( $_POST['action'] ) && $_POST['action'] === 'submit_employee_feedback' ) {
                $data = array(
                    'name'              => sanitize_text_field( $_POST['name'] ),
                    'yesterdays_tasks'  => sanitize_textarea_field( $_POST['yesterdays_tasks'] ),
                    'todays_tasks'      => sanitize_textarea_field( $_POST['todays_tasks'] ),
                    'blockers'          => sanitize_textarea_field( $_POST['blockers'] ),
                );

                set_transient( 'employee_feedback_' . time(), json_encode( $data ), 7 * DAY_IN_SECONDS );

                // Send message to Matter Most
                $materMostMessage = $this->sendMessageToMatterMost( $data );

                // Predefined message after successfully submission
                $_SESSION['feedback_success'] = 'Feedback submitted successfully!';

                // If not null store message
                if (!is_null( $materMostMessage ) ) {
                    $_SESSION['matter_most'] = $materMostMessage;
                }

                // Redirect to the form
                wp_safe_redirect( $_POST['_wp_http_referer'] );
                exit;

            }
        }

        /**
         * Send messages to matter most
         *
         * @param array $message
         * @return string|null
         */
        public function sendMessageToMatterMost ( array $message ): string|null
        {
            // Getting ready all te data to send to the webhook
            $data = array(
                'channel'   => 'wp-team-activity', // wp-team-activity test-bots
                'username'  => 'Bitbucket Pipelines',
                'text'      => '##### Name: **' .$message["name"]. '** 
**Previous**: ' .$this->convertJiraTicketsMd( $message["yesterdays_tasks"] ). '
**Current**: ' .$this->convertJiraTicketsMd( $message["todays_tasks"] ). '
**Blockers**: ' .$this->convertJiraTicketsMd( $message["blockers"] )
            );

            // Setting the request
            $response = wp_remote_post('https://matter.dblexchange.com/hooks/t34yjuo6a3refeeafr3itdauge', array(
                'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
                'body'        => json_encode($data),
                'method'      => 'POST',
                'data_format' => 'body',
            ));

            // Check for errors
            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                return "Something went wrong: $error_message";
            }
            else {
                return null;
            }

        }

        /**
         * Add a method to convert Jira ticket numbers into html links
         *
         * @param string $text
         * @return string
         */
        private function convertJiraTickets( string $text ): string
        {
            // This regex looks for Jira ticket patterns not already part of a link
            return preg_replace('/(?<!href="https:\/\/jira\.cltbcanada\.net\/browse\/)\b[A-Z]{4}-\d{3,4}\b/', '<a href="https://jira.cltbcanada.net/browse/$0">$0</a>', $text);
        }

        /**
         * Add a method to convert Jira ticket numbers into markdown links
         *
         * @param string $text
         * @return string
         */
        private function convertJiraTicketsMd( string $text ): string
        {
            // This regex looks for Jira ticket patterns not already part of a link
            return preg_replace('/(?<!href="https:\/\/jira\.cltbcanada\.net\/browse\/)\b[A-Z]{4}-\d{3,4}\b/', '[$0](https://jira.cltbcanada.net/browse/$0)', $text);
        }

        /**
         * Add a menu page in the admin dashboard to display feedback data
         *
         * @return void
         */
        public function addMenuPage(): void
        {
            add_menu_page( 'Feedback Data', 'Feedback Data', 'manage_options', 'feedback-data', array( $this, 'displayFeedbackData' ) );
        }

        /**
         * Display the feedback data on the dashboard
         *
         * @return void
         */
        public function displayFeedbackData(): void
        {
            ?>
            <div class="wrap">
                <h2>Employee Feedback Data</h2>
                <table class="wp-list-table widefat fixed">
                    <thead>
                        <tr>
                            <th>Date Log</th>
                            <th>Developer Name</th>
                            <th>Yesterday's Tasks</th>
                            <th>Today's Tasks</th>
                            <th>Blockers</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        global $wpdb;
                        $table_name = $wpdb->options;
                        $transient_prefix = '_transient_employee_feedback_';

                        $transients = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT option_name, option_value FROM {$table_name}
                                WHERE option_name LIKE %s
                                ORDER BY option_name DESC",
                                $transient_prefix . '%'
                            )
                        );

                        if ( $transients ) {
                            foreach ( $transients as $transient ) {
                                $data = json_decode( $transient->option_value, true );
                                if ( $data ) {
                                    $datetime = date('Y-m-d H:i:s', substr($transient->option_name, strlen($transient_prefix)));

                                    echo '<tr>';
                                    echo '<td>' . $datetime . '</td>';
                                    echo '<td>' . esc_html($data['name']) . '</td>';
                                    echo '<td>' . $this->convertJiraTickets(stripslashes($data['yesterdays_tasks'])) . '</td>';
                                    echo '<td>' . $this->convertJiraTickets(stripslashes($data['todays_tasks'])) . '</td>';;
                                    echo '<td>' . esc_html(stripslashes($data['blockers'])) . '</td>';
                                    echo '<td><button class="delete-feedback" data-transient="' . esc_attr($transient->option_name) . '">Delete</button></td>';
                                    echo '</tr>';
                                }
                            }
                        } 
                        else {
                            echo '<tr><td colspan="7">No data found.</td></tr>';
                                }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        /**
         * Display a notice if Contact Form 7 is not installed
         *
         * @return void
         */
        public function cf7NotInstalledMessage(): void
        {
            ?>
            <div class="error">
                <p>Contact Form 7 is not installed. This plugin requires Contact Form 7 to function.</p>
            </div>
            <?php
        }

        /**
         * Enqueue JavaScript
         *
         * @return void
         */
        public function enqueueScripts(): void
        {
            wp_enqueue_script( 'employee-feedback-script', plugin_dir_url(__FILE__) . 'employee-feedback-script.js', '', '1.0', true );

            // Pass the admin-ajax URL to JavaScript
            wp_localize_script( 'employee-feedback-script', 'employee_feedback_data', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
            ) );
        }

        /**
         * Handle AJAX request to delete feedback
         *
         * @return void
         */
        public function deleteEmployeeFeedback() 
        {
            if ( isset( $_REQUEST['transient_name'] ) ) {
                $transientName = sanitize_text_field( $_POST['transient_name'] );

                // Remove the "_transient_" prefix
                $transientName = str_replace( '_transient_', '', $transientName );

                // Delete the transient
                delete_transient( $transientName );

                echo 'success';
            } 
            else {
                echo 'error';
            }

            exit;
        }

        /**
         * Activate the plugin
         *
         * @return void
         */
        public function activatePlugin() 
        {
            // Create a new page with the title "Employee Feedback" and content
            $page_title     = 'Employee Feedback';
            $page_content   = '<p>Please fill the following form to send your report.</p><br>[employee_feedback_form]';
            $page_slug      = 'employee-feedback';

            // Check if the page exists
            $existing_page  = get_page_by_path( $page_slug );

            if ( !$existing_page ) {
                // Page doesn't exist, create a new one
                $page = array(
                    'post_title'    => $page_title,
                    'post_content'  => $page_content,
                    'post_name'     => $page_slug,
                    'post_status'   => 'publish',
                    'post_type'     => 'page',
                );

                // Insert the page into the database
                wp_insert_post( $page );
            }
            elseif ( $existing_page->post_status === 'draft' ) {
                // Page exists but is in 'draft' status, update it to 'publish'
                $existing_page->post_status = 'publish';
                wp_update_post( $existing_page );
            }
        }

        /**
         * Deactivate the plugin
         *
         * @return void
         */
        public function deactivatePlugin() 
        {
            // Get the page ID by slug
            $page = get_page_by_path( 'employee-feedback' );

            // Check if the page exists
            if ( $page ) {
                // Set the page status to 'draft' when deactivating the plugin
                $page->post_status = 'draft';
                wp_update_post( $page );
            }
        }

        /**
         * Uninstall the plugin
         *
         * @return void
         */
        public function uninstallPlugin(): void
        {
            // Delete all transients created by the plugin
            global $wpdb;
            $transient_prefix   = '_transient_employee_feedback_';
            $transients        = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options}
                    WHERE option_name LIKE %s",
                    $transient_prefix . '%'
                )
            );

            if ( $transients ) {
                foreach ( $transients as $transient ) {
                     // Remove the "_transient_" prefix
                    $transientName = str_replace( '_transient_', '', $transient );
                    delete_transient( $transientName );
                }
            }

            // Get the page ID by slug
            $page = get_page_by_path( 'employee-feedback' );

            // Check if the page exists
            if ( $page ) {
                // Delete the page permanently
                wp_delete_post( $page->ID, true );
            }
        }
    }

    $employee_feedback_form = new EmployeeFeedbackForm();
}
