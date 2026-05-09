<?php
return array (
  'title' => 'DSXBoard',

  # Unsafe default "changeme" Generate with:
  # php -r 'echo password_hash("yourpw", PASSWORD_ARGON2ID), PHP_EOL;'
  'edit_password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$M05TZ2Z1QWtvYVo5eFQuRA$zoFiFa4Uxcz4b66b78OYlzVZes9wA9cO0OL/nOwv+yE',
  
  'edit_session_ttl' => 3600,
  'links' => [
        ['category' => 'Infrastructure', 'name' => 'Gatekeeper',       'url' => 'https://pfs.example.int',                   'icon' => '🛡',  'desc' => 'OPNsense — firewall & routing'],
        ['category' => 'Infrastructure', 'name' => 'vCenter (PS)',     'url' => 'https://vcs-ps.example.int',                'icon' => '🖥',  'desc' => 'VCS 7 cluster'],
        ['category' => 'Infrastructure', 'name' => 'NetBox',           'url' => 'https://netbox.example.int',                'icon' => '📦', 'desc' => 'IPAM & DCIM'],
        ['category' => 'Infrastructure', 'name' => 'UniFi',            'url' => 'https://unifi.example.int',                 'icon' => '📡', 'desc' => 'Network controller'],
 
        // ---- Monitoring ---------------------------------------------------
        ['category' => 'Monitoring',     'name' => 'Icinga Web',       'url' => 'https://icinga2.example.int/icingaweb2/',   'icon' => '✓',  'desc' => 'Host & service checks'],
        ['category' => 'Monitoring',     'name' => 'Grafana (Icinga)', 'url' => 'https://grafana.example.int/grafana/',      'icon' => '📊', 'desc' => 'Dashboards on Icinga data'],
        ['category' => 'Monitoring',     'name' => 'Zabbix',           'url' => 'https://zabbix.example.int',                'icon' => 'Z',  'desc' => 'Infrastructure monitoring'],
        ['category' => 'Monitoring',     'name' => 'Grafana (MQTT)',   'url' => 'http://mqtt.example.int:3000/login',        'icon' => '📈', 'desc' => 'Dashboards on MQTT data'],
        ['category' => 'Monitoring',     'name' => 'Node-RED',         'url' => 'http://mqtt.example.int:1880/',             'icon' => '🔴', 'desc' => 'Flow-based automation'],
 
        // ---- Services -----------------------------------------------------
        ['category' => 'Services',       'name' => 'Nextcloud',        'url' => 'https://cloud.example.net',                 'icon' => '☁',  'desc' => 'Files & collaboration'],
        ['category' => 'Services',       'name' => 'Forgejo',          'url' => 'https://forgejo.example.net',               'icon' => '🌿', 'desc' => 'Self-hosted git'],
        ['category' => 'Services',       'name' => 'Element (Matrix)', 'url' => 'https://neo.example.net',                   'icon' => '💬', 'desc' => 'Synapse + Sydent'],
        ['category' => 'Services',       'name' => 'Nginx Proxy',      'url' => 'https://nginx-proxy.example.net/login',     'icon' => '🔀', 'desc' => 'Reverse proxy manager'],
        ['category' => 'Services',       'name' => 'Portainer',        'url' => 'https://portainer.example.net',             'icon' => '🐳', 'desc' => 'Container management'],
 
        // ---- Storage ------------------------------------------------------
        ['category' => 'Storage',        'name' => 'Ceph MGMT',        'url' => 'https://ceph-fs.example.int',               'icon' => '🐙', 'desc' => 'Ceph cluster management'],
        ['category' => 'Storage',        'name' => 'TrueNAS',          'url' => 'https://truenas.example.int',               'icon' => '💿', 'desc' => 'TrueNAS storage'],
        ['category' => 'Storage',        'name' => 'Iperius',          'url' => 'https://back.example.int',                  'icon' => '🗄',  'desc' => 'Backup server'],
 
        // ---- External -----------------------------------------------------
        ['category' => 'External',       'name' => 'Entra Admin',      'url' => 'https://entra.microsoft.com',              'icon' => 'E',  'desc' => 'Identity & access'],
        ['category' => 'External',       'name' => 'Intune',           'url' => 'https://intune.microsoft.com',             'icon' => 'I',  'desc' => 'Endpoint management'],
        ['category' => 'External',       'name' => 'M365 Admin',       'url' => 'https://admin.microsoft.com',              'icon' => 'M',  'desc' => 'Tenant administration'],
    ],
);
