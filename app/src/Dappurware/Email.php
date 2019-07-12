<?php

namespace Dappur\Dappurware;

use Carbon\Carbon;
use Interop\Container\ContainerInterface;
use Respect\Validation\Validator as V;

class Email extends Dappurware
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
    }

    public function addTemplate()
    {
        $this->validateTemplate();

        if ($this->validator->isValid()) {
            $requestParams = $this->container->request->getParams();

            $addTemplate = new \Dappur\Model\EmailsTemplates;
            $addTemplate->name = $requestParams['name'];
            $addTemplate->slug = $requestParams['slug'];
            $addTemplate->description = $requestParams['description'];
            $addTemplate->subject = $requestParams['subject'];
            $addTemplate->html = $requestParams['html'];
            if ($requestParams['placeholders']) {
                $addTemplate->placeholders = json_encode($requestParams['placeholders']);
            }

            if ($addTemplate->save()) {
                return true;
            }
        }

        return false;
    }

    public function updateTemplate($templateId = null)
    {
        $template = new \Dappur\Model\EmailsTemplates);
        $template = $template->find($templateId);

        $this->validateTemplate($templateId);

        if ($this->validator->isValid()) {
            $requestParams = $this->container->request->getParams();

            $template->name = $requestParams['name'];
            $template->slug = $requestParams['slug'];
            $template->description = $requestParams['description'];
            $template->subject = $requestParams['subject'];
            $template->html = $requestParams['html'];
            if ($requestParams['placeholders']) {
                $template->placeholders = json_encode($requestParams['placeholders']);
            }

            if ($template->save()) {
                return true;
            }
        }

        return false;
    }

    private function validateTemplate($templateId = null)
    {
        $requestParams = $this->container->request->getParams();

        $this->container->validator->validate(
            $this->container->request,
            array(
                'name' => array(
                    'rules' => V::alnum('\'-".,?!@#$%^&')->length(4, 32),
                    'messages' => array(
                        'alnum' => 'May only contain letters and numbers.',
                        'length' => 'Must be between 4 and 32 characters.'
                    )
                ),
                'slug' => array(
                    'rules' => V::slug()->length(4, 32),
                    'messages' => array(
                        'slug' => 'Must be in slug format.',
                        'length' => 'Must be between 4 and 32 characters.'
                    )
                ),
                'description' => array(
                    'rules' => V::alnum('\'-".,?!@#$%^&')->length(4, 255),
                    'messages' => array(
                        'alnum' => 'May only contain letters and numbers.',
                        'length' => 'Must be between 4 and 255 characters.'
                    )
                )
            )
        );

        // Check for Dupe Slug
        $slugCheck = new \Dappur\Model\EmailsTemplates;
        $slugCheck = $slugCheck->where('slug', $requestParams['slug']);
        if ($templateId) {
            $slugCheck = $slugCheck->where('id', '!=', $templateId);
        }
        if ($slugCheck->first()) {
            $this->container->validator->addError('slug', 'Slug is already in use.');
        }

        // Process Placeholders
        if ($requestParams['placeholders']) {
            foreach ($requestParams['placeholders'] as $plvalue) {
                if (!V::alnum('_')->lowercase()->validate($plvalue)) {
                    $this->container->validator->addError(
                        'placeholders',
                        'All custom data fields must be in this_format.'
                    );
                }
            }
        }

        return true;
    }

    public function getPlaceholders()
    {
        $output = array();

        $users = \Dappur\Model\Users::first()->toArray();

        foreach (array_keys($users) as $key) {
            if ($key == "password" || $key == "permissions") {
                continue;
            }
            
            $output['User Info'][] = array("name" => "user_" . str_replace("-", "_", $key));
        }

        // Only include Site Settings
        $siteSettings = \Dappur\Model\ConfigGroups::with('config')->find(1);

        foreach ($siteSettings->config as $value2) {
            $output[$siteSettings->name][] = array(
                "name" => "settings_" . str_replace("-", "_", $value2->name),
                "value" => $value2->value
            );
        }
            
        return $output;
    }

    public function sendTemplate(array $sendTo, $templateSlug = null, array $params = null)
    {
        $output = array();

        $placeholders = $this->getPlaceholders();

        $template = \Dappur\Model\EmailsTemplates::where('slug', '=', $templateSlug)->first();

        $recipients = $this->parseRecipients($sendTo);

        if (!empty($recipients)) {
            if ($template) {
                // Get Email Bodies
                $html = $template->html;
                $subject = $template->subject;
                
                // Process Custom Placeholders
                $tplaceholders = json_decode($template->placeholders);

                if ($tplaceholders) {
                    foreach ($tplaceholders as $value) {
                        if (isset($params[$value])) {
                            $placeholders['Template'][] = array("name" => $value, "value" => $params[$value]);
                        }
                    }
                }

                // Send Email To Users
                if (!empty($recipients['users'])) {
                    foreach ($recipients['users'] as $uvalue) {
                        // Process Bodies with Custom and System Placeholders
                        $userTemp = \Dappur\Model\Users::find($uvalue);
                        $placeholdersTemp = $placeholders;

                        if ($userTemp) {
                            foreach ($placeholders['User Info'] as $key => $sssvalue) {
                                $tempValue = str_replace("user_", "", $sssvalue['name']);
                                $placeholdersTemp['User Info'][$key]['value'] = $userTemp[$tempValue];
                            }
                        }

                        $placeholdersTemp = $this->preparePlaceholders($placeholdersTemp);

                        $sendEmail = $this->processAndSend(
                            $html,
                            $subject,
                            $placeholdersTemp,
                            $userTemp->email,
                            $template->id
                        );
                        if ($sendEmail['result']) {
                            $output['status'] = "success";
                        }
                    }
                }

                // Send Email to Email Addresses
                if (!empty($recipients['email'])) {
                    $placeholdersTemp = $this->preparePlaceholders($placeholders);
                    
                    foreach ($recipients['email'] as $evalue) {
                        $sendEmail = $this->processAndSend($html, $subject, $placeholdersTemp, $evalue, $template->id);
                        if ($sendEmail['result']) {
                            $output['status'] = "success";
                        }
                    }
                }
            }

            if (!$template) {
                $output['status'] = "error";
                $output['message'] = "No valid template was selected.";
            }
        }

        if (empty($recipients)) {
            $output['status'] = "error";
            $output['message'] = "There were no recipients to send to.";
        }

        return $output;
    }

    public function sendEmail(array $sendTo, $subject, $html)
    {
        $output = array();

        $placeholders = Email::getPlaceholders();

        $recipients = Email::parseRecipients($sendTo);

        if (!empty($recipients)) {
            // Send Email To Users
            if (!empty($recipients['users'])) {
                foreach ($recipients['users'] as $uvalue) {
                    // Process Bodies with Custom and System Placeholders
                    $userTemp = \Dappur\Model\Users::find($uvalue);
                    $placeholdersTemp = $placeholders;

                    if ($userTemp) {
                        foreach ($placeholders['User Info'] as $key => $sssvalue) {
                            $placeholdersTemp['User Info'][$key]['value'] =
                                $userTemp[str_replace("user_", "", $sssvalue['name'])];
                        }
                    }

                    $placeholdersTemp = $this->preparePlaceholders($placeholdersTemp);

                    $sendEmail = $this->processAndSend($html, $subject, $placeholdersTemp, $userTemp->email);
                    if ($sendEmail['result']) {
                        $output['status'] = "success";
                    }
                }
            }

            // Send Email to Email Addresses
            if (!empty($recipients['email'])) {
                foreach ($recipients['email'] as $evalue) {
                    $placeholdersTemp = $this->preparePlaceholders($placeholders);
                    $sendEmail = $this->processAndSend($html, $subject, $placeholdersTemp, $evalue);
                    if ($sendEmail['result']) {
                        $output['status'] = "success";
                    }
                }
            }
        }

        if (empty($recipients)) {
            $output['status'] = "error";
            $output['message'] = "There were no recipients to send to.";
        }
        
        return $output;
    }

    private function processAndSend($html, $subject, $placeholders, $recipient, $templateId = null)
    {
        // Process HTML Email
        $htmlOut = new \Twig_Environment(new \Twig_Loader_Array(['email_html' => $html]));
        $htmlOut = $htmlOut->render('email_html', $placeholders);

        // Process Plain Text Email
        $plainTextOut = \Html2Text\Html2Text::convert($htmlOut);

        //Process Subject
        $subjectOut = new \Twig_Environment(new \Twig_Loader_Array(['email_sub' => $subject]));
        $subjectOut = $subjectOut->render('email_sub', $placeholders);

        $sendEmail = Email::send($recipient, html_entity_decode($subjectOut), $htmlOut, $plainTextOut, $templateId);

        return $sendEmail;
    }

    private function parseRecipients(array $sendTo)
    {
        $output = array();
        $output['users'] = array();
        foreach ($sendTo as $value) {
            // If int, get user email
            if ($this->auth->findById($value)) {
                $output['users'][] = $value;
            }

            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                // If email, check if user
                $emailUser = $this->container->auth->findByCredentials(['login' => $value]);
                if ($emailUser) {
                    if (!in_array($value, $output['users'])) {
                        $output['users'][] = $emailUser->id;
                    }
                }

                if (!$emailUser) {
                    $output['email'][] = $value;
                }
            }

            //if other, check to see if user role exists for slug
            $role = $this->auth->findRoleBySlug($value);
            if ($role) {
                $users = $role->users()->get(['id']);
                foreach ($users as $value) {
                    if (!in_array($value, $output['users'])) {
                        $output['users'][] = $value['id'];
                    }
                }
            }
        }

        return $output;
    }

    private function preparePlaceholders($placeholders)
    {
        $output = array();

        foreach ($placeholders as $value) {
            foreach ($value as $value2) {
                if (isset($value2['value'])) {
                    $output[$value2['name']] = $value2['value'];
                }
            }
        }

        return $output;
    }

    private function send($email, $subject, $html, $plainText, $templateId = null)
    {
        $output = array();

        $mail = $this->mail;
        $mail->ClearAllRecipients();
        $mail->setFrom($this->config['from-email']);
        $mail->addAddress($email);
        $mail->addReplyTo($this->config['from-email']);
        $mail->isHTML(true);

        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $plainText;

        // Message ID
        $messageId = \Ramsey\Uuid\Uuid::uuid4()->toString().'@'.$this->config['domain'];
        $mail->MessageID = "<" . $messageId .'>';

        $sendEmail = $mail->send();

        if ($this->settings['mail']['logging']) {
            //Delete Old Emails
            if ($this->settings['mail']['log_retention']) {
                \Dappur\Model\Emails::where(
                    'created_at',
                    '<',
                    Carbon::now()->subDays($this->settings['mail']['log_retention'])
                )->delete();
            }

            $addEmail = new \Dappur\Model\Emails;
            $addEmail->secure_id = $messageId;
            $addEmail->template_id = $templateId;
            $addEmail->send_to = $email;
            $addEmail->subject = $subject;
            if (in_array("html", $this->settings['mail']['log_details'])) {
                $addEmail->html = $html;
            }
            if (in_array("plain_text", $this->settings['mail']['log_details'])) {
                $addEmail->plain_text = $plainText;
            }
            $addEmail->save();

            $addStatus = new \Dappur\Model\EmailsStatus;
            $addStatus->email_id = $addEmail->id;
            if (!$sendEmail) {
                $addStatus->status = "error";
                $addStatus->details = $mail->ErrorInfo;
            }
            if ($sendEmail) {
                $addStatus->status = 'sent';
            }
            $addStatus->save();
        }

        if (!$sendEmail) {
            $output['result'] = false;
            $output['error'] = $mail->ErrorInfo;
            return $output;
        }

        $output['result'] = true;

        return $output;
    }
}
