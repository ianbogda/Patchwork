<?php /****************** vi: set fenc=utf-8 ts=4 sw=4 et: *****************
 *
 *   Copyright : (C) 2012 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/agpl.txt GNU/AGPL
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 ***************************************************************************/


class agent_queue_pMail extends agent_queue_pTask
{
    protected

    $queueFolder = 'data/queue/pMail/',
    $dual = 'pMail';


    protected function queueNext()
    {
        $time = time();
        $sql = "SELECT OID, base, send_time FROM queue WHERE send_time>0 ORDER BY send_time, OID LIMIT 1";
        if ($data = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC))
        {
            $data = $data[0];

            if ($data['send_time'] <= $time)
            {
                $sql = "UPDATE queue SET send_time=0
                        WHERE OID={$data['OID']} AND send_time>0";
                if ($this->db->exec($sql)) tool_url::touch("{$data['base']}queue/pMail/{$data['OID']}/" . $this->getToken());
            }
            else pTask::schedule(new pTask(array($this, 'control')), $data['send_time']);
        }
    }

    protected function doAsap($id)
    {
        $sql = "UPDATE queue SET send_time=1
                WHERE OID={$id} AND send_time=0";
        $this->db->exec($sql);
    }

    protected function doOne($id)
    {
        $db = $this->db;

        $sql = "SELECT archive, data FROM queue WHERE OID={$id} AND send_time=0";
        $data = $db->query($sql)->fetchAll(PDO::FETCH_NUM);

        if (!$data) return;

        $archive = $data[0][0];
        $data = (object) unserialize($data[0][1]);

        $this->restoreContext($data->cookie, $data->session);

        try
        {
            $e = $data->mailer;
            $e = new $e($data->headers, $data->options);
            $e->send();
        }
        catch (Exception $e)
        {
            echo "Exception on pMail #{$id}:\n\n";
            print_r($e);
            $archive = 1;
        }

        $sql = $archive
            ? "UPDATE queue SET sent_time={$_SERVER['REQUEST_TIME']}, send_time=0 WHERE OID={$id}"
            : "DELETE FROM queue WHERE OID={$id}";
        $db->exec($sql);
    }
}
