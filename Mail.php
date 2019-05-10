<?php

namespace app;

/**
 * Class Mail
 *
 * @author Yuri Velmesov <yuri.velmesov@gmail.com>
 *
 * @package app
 */
class Mail
{
    /**
     * @var object $instance
     */
    private static $instance;

    /**
     * Настройки SMTP
     *
     * @var array $confSMTP
     */
    private $confSMTP;

    /**
     * Получатель
     *
     * @var array $to
     */
    private $to;

    /**
     * Отправитель
     *
     * @var array $from
     */
    private $from;

    /**
     * Тема сообщения
     *
     * @var string $subject
     */
    private $subject;

    /**
     * Текст сообщения
     *
     * @var string $message
     */
    private $message;

    /**
     * Данные вложений в base64
     *
     * @var string $attachments
     */
    private $attachments = '';

    /**
     * Разделитель в сообщении
     *
     * @var string $boundary
     */
    private $boundary;

    /**
     * Инициализация объекта
     *
     * @return object
     */
    public static function init()
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Подключение конфига
     *
     * @return void
     */
    private function loadConfSMTP()
    {
        $this->confSMTP = require $_SERVER['DOCUMENT_ROOT'] . '/conn/smtp.php';
    }

    /**
     * Отправка письма стандартной функцией mail
     *
     * @param string $to          Получатель      Пример: "Имя <EMAIL>" или "EMAIL"
     * @param string $from        Отправитель     Пример: "Имя <EMAIL>" или "EMAIL"
     * @param string $subject     Тема сообщения
     * @param string $message     Текст сообщения
     * @param array  $attachments Массив списка файлов
     * @param bool   $smtp        Флаг отправки через SMTP сервер
     *
     * @return array Статус отправки или ошибка
     */
    public function send(string $to, string $from, string $subject = '', string $message = '', array $attachments = [], bool $smtp = false): array
    {
        $prepareTo = $this->prepareTo($to);

        if (!$prepareTo['status']) {
            return $prepareTo;
        }

        $prepareFrom = $this->prepareFrom($from);

        if (!$prepareFrom['status']) {
            return $prepareFrom;
        }

        $this->prepareSubject($subject);
        $this->prepareMessage($message);

        if ($prepareTo['status'] && $prepareFrom['status']) {
            if (empty($this->from['name'])) {
                $header_from = $this->from['email'];
            } else {
                $header_from = '=?UTF-8?B?'.base64_encode($this->from['name']).'?= <'.$this->from['email'].'>';
            }

            // Если отправка через SMTP
            if ($smtp) {
                return $this->sendSMTP($to, $from, $subject, $message, $attachments);
            // Если обычная отправка
            } else {
                // Если нет прикрепленных файлов
                if (empty($attachments)) {
                    // Заголовки
                    $headers = [
                        'From'                      => $header_from,
                        'Content-Type'              => 'text/html; charset="UTF-8"',
                        'Content-Transfer-Encoding' => 'base64'
                    ];

                    $message = $this->message;
                // Если есть прикрепленные файлы
                } else {
                    // Создаем разделитель
                    $this->createBoundary();

                    // Подготовка вложений для отправки
                    $prepareAttachments = $this->prepareAttachments($attachments);

                    if ($prepareAttachments['status']) {
                        // Заголовки
                        $headers = [
                            'From'         => $header_from,
                            'Content-Type' => 'multipart/mixed; boundary="'.$this->boundary.'"'
                        ];

                        // Формируем тело письма
                        $message = "--$this->boundary\n";
                        $message .= "Content-Type: text/html; charset=\"UTF-8\"\n";
                        $message .= "Content-Transfer-Encoding: base64\n\n";
                        $message .= $this->message;
                        $message .= "--$this->boundary\n";
                        $message .= $this->attachments;
                    } else {
                        return $prepareAttachments;
                    }
                }

                // Отправляем сообщение
                if (mail($to, $this->subject, $message, $headers)) {
                    return [
                        'status'  => true,
                        'message' => 'Сообщение успешно отправлено'
                    ];
                } else {
                    return [
                        'status'  => false,
                        'error'   => 'err_send',
                        'message' => 'Возникла ошибка при отправке почты'
                    ];
                }
            }
        }
    }

    /**
     * Отправка письма через SMTP сервер
     *
     * @param string $to          Email получателя
     * @param string $from        Email отправителя
     * @param string $subject     Тема сообщения
     * @param string $msg         Текст сообщения
     * @param array  $attachments Массив списка файлов
     *
     * @return array Статус отправки или ошибка
     */
    private function sendSMTP(string $to, string $from, string $subject, string $msg, array $attachments): array
    {
        // Подключаем конфиг
        $this->loadConfSMTP();

        // Данные письма
        $from_name  = $this->confSMTP[$from]['name'];
        $from_email = $this->confSMTP[$from]['email'];
        $pass       = $this->confSMTP[$from]['pass'];
        $host       = $this->confSMTP[$from]['host'];
        $port       = $this->confSMTP[$from]['port'];

        // Обработка вложений
        $files = '';

        if (!empty($attachments)) {
            $count     = count($attachments) - 1;
            $delimiter = '|';

            foreach($attachments as $key => $path) {
                $files .= $key == $count ? $path : $path.$delimiter;
            }
        }

        // Формируем команду
        $cmd = '$script -to '.$to.' -fname "'.$from_name.'" -femail '.$from_email.' -subject "'.$subject.'" -msg "'.$msg.'" -files "'.$files.'" -pass '.$pass.' -host '.$host.' -port '.$port;

        // Путь к скрипту
        $script = $_SERVER['DOCUMENT_ROOT'].'/tasks/mail/send';

        // Выполняем отправку
        exec($cmd, $output, $return);

        if ($return == 0) {
            return [
                'status'  => true,
                'message' => 'Сообщение успешно отправлено'
            ];
        } else {
            return [
                'status'  => false,
                'error'   => 'error_send_smtp',
                'message' => 'Ошибка отправки сообщения через SMTP сервер'
            ];
        }
    }

    /**
     * Подготовка Получателя
     *
     * @param  string $to Получатель
     *
     * @return array
     */
    private function prepareTo(string $to): array
    {
        $destination = $this->checkDestination($to);

        if ($destination['status']) {
            $this->to['name']  = $destination['name'];
            $this->to['email'] = $destination['email'];

            return [
                'status' => true
            ];
        } else {
            return [
                'status'  => false,
                'error'   => 'error_to',
                'message' => 'Некорректные данные получателя'
            ];
        }
    }

    /**
     * Подготовка Отправителя
     *
     * @param  string $from Отправитель
     *
     * @return array
     */
    private function prepareFrom(string $from): array
    {
        $destination = $this->checkDestination($from);

        if ($destination['status']) {
            $this->from['name']  = $destination['name'];
            $this->from['email'] = $destination['email'];

            return [
                'status' => true
            ];
        } else {
            return [
                'status'  => false,
                'error'   => 'error_from',
                'message' => 'Некорректные данные отправителя'
            ];
        }
    }

    /**
     * Проверка адресата
     *
     * @param string $destination
     *
     * @return array
     */
    private function checkDestination(string $destination): array
    {
        $destination = trim($destination);

        if (preg_match('/^((?<name>[a-zа-яё0-9\s#№.-]{2,50})[\s]{1}|)([<]{1}|)(?<email>[a-z0-9.-]{2,50}@[a-z0-9.-]{2,50}.[a-z]{2,20})([>]{1}|)$/ui', $destination, $matches)) {
            return [
                'status' => true,
                'name'   => trim($matches['name']),
                'email'  => mb_strtolower($matches['email'])
            ];
        } else {
            return [
                'status' => false
            ];
        }
    }

    /**
     * Подготовка Темы сообщения
     *
     * @param  string $subject Тема сообщения
     *
     * @return void
     */
    private function prepareSubject(string $subject)
    {
        $this->subject = empty($subject) ? '' : '=?UTF-8?B?'.base64_encode($subject).'?=';
    }

    /**
     * Подготовка Текста сообщения
     *
     * @param  string $message Текст сообщения
     *
     * @return void
     */
    private function prepareMessage(string $message)
    {
        $this->message = chunk_split(base64_encode($message), 76, "\n");
    }

    /**
     * Подготовка вложений для отправки
     *
     * @param  array $attachments Массив вложений
     *
     * @return array
     */
    private function prepareAttachments(array $attachments): array
    {
        $count = count($attachments) - 1;

        foreach($attachments as $key => $path) {
            $file_name = basename($path);

            if (!file_exists($path)) {
                return [
                    'status'  => false,
                    'error'   => 'file_not_found',
                    'message' => 'Файл "'.$file_name.'" не найден'
                ];
            }

            $file       = file_get_contents($path);
            $mime_type  = mime_content_type($path);
            $attachment = chunk_split(base64_encode($file), 76, "\n");

            $attach = "Content-Type: $mime_type; name=\"$file_name\"\n";
            $attach .= "Content-Disposition: attachment; filename=\"$file_name\"\n";
            $attach .= "Content-Transfer-Encoding: base64\n\n";
            $attach .= $attachment;
            $attach .= $count == $key ? "--$this->boundary--\n" : "--$this->boundary\n";

            $this->attachments .= wordwrap($attach, 76, "\n");
        }

        return [
            'status' => true
        ];
    }

    /**
     * Создание разделителя
     *
     * @return void
     */
    function createBoundary()
    {
        $this->boundary = uniqid('00000', true);
    }

    private function __clone()
    {
    }

    private function __wakeup()
    {
    }
}
