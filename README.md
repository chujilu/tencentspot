# tencentCloudProxy

腾讯云竞价实例自动创建程序

## install

```
composer install
```

## start

* 注册腾讯云账户
* 添加api秘钥
* 硅谷区创建安全分组，允许全部端口或22端口
* `.env.local`内配置秘钥
* php run.php
* 浏览器安装SwitchyOmega 配置socks5 127.0.0.1 1080端口

## other

* 默认连接15分钟后自动销毁。
* 默认创建硅谷区最低配机器
* 费用是0.05每小时+0.5每G流量
