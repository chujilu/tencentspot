<?php
//查询配置信息-秘钥-安全组-实例名称-镜像
//查询已有实例
//开通实例
//获取实例信息
//获取本地端口或进程占用
//清理已有信息
//后台启动ssh进程
//20分钟后检查关闭ssh进程
require 'vendor/autoload.php';

// 导入对应产品模块的client
use Symfony\Component\Dotenv\Dotenv;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Cvm\V20170312\CvmClient;
// 导入要请求接口对应的Request类
use TencentCloud\Common\Credential;
use TencentCloud\Cvm\V20170312\Models\ActionTimer;
use TencentCloud\Cvm\V20170312\Models\DescribeInstancesRequest;
use TencentCloud\Cvm\V20170312\Models\DescribeInstanceTypeConfigsRequest;
use TencentCloud\Cvm\V20170312\Models\DescribeZonesRequest;
use TencentCloud\Cvm\V20170312\Models\Externals;
use TencentCloud\Cvm\V20170312\Models\Filter;
use TencentCloud\Cvm\V20170312\Models\InquiryPriceRunInstancesRequest;
use TencentCloud\Cvm\V20170312\Models\Instance;
use TencentCloud\Cvm\V20170312\Models\InstanceMarketOptionsRequest;
use TencentCloud\Cvm\V20170312\Models\InstanceTypeConfig;
use TencentCloud\Cvm\V20170312\Models\InternetAccessible;
use TencentCloud\Cvm\V20170312\Models\LoginSettings;
use TencentCloud\Cvm\V20170312\Models\Placement;
use TencentCloud\Cvm\V20170312\Models\RunInstancesRequest;
use TencentCloud\Cvm\V20170312\Models\SpotMarketOptions;
use TencentCloud\Cvm\V20170312\Models\SystemDisk;
use TencentCloud\Cvm\V20170312\Models\TerminateInstancesRequest;
use TencentCloud\Cvm\V20170312\Models\ZoneInfo;
use TencentCloud\Vpc\V20170312\Models\DescribeSecurityGroupsRequest;
use TencentCloud\Vpc\V20170312\Models\SecurityGroup;
use TencentCloud\Vpc\V20170312\VpcClient;

class Config
{
    public static $SecretId = '';
    public static $SecretKey = '';

    public static $instanceName = 'spot-paid-proxy';
    public static $imageId = 'img-pi0ii46r';
    public static $chargeType = 'SPOTPAID';

    public static $region = 'na-siliconvalley';
//    public static $region = 'eu-moscow';
//    public static $securityGroupId = 'sg-rjdzursf';
    public static $loginKeyId = 'skey-643p9cs1';

    public static $maxPrice = 0.05;
    public static $maxTime = 60*15;

    public static function init()
    {
        self::$SecretId = getenv('SECRETID');
        self::$SecretKey = getenv('SECRETKEY');
    }
}

class Run
{
    protected $action;
    /**
     * @var CvmClient
     */
    protected $client;
    /**
     * @var VpcClient
     */
    private $vpcClient;

    public function __construct($action)
    {
        $this->action = empty($action) ? 'run' : $action;

        (new Dotenv())->loadEnv(__DIR__.'/.env');
        Config::init();
        $this->initClient();
    }

    public function execute()
    {
        if ($this->action == 'run') {
            $this->checkLocal();

            $instance = $this->getExistInstance();
            if ($instance === null || !$this->checkInstance($instance)) {
                //创建
                echo "创建实例 \r\n";
                $instanceId = $this->createInstance();
                sleep(1);
            }

            $this->registerShutdown();

            $instance = $this->waitInstanceRun();
            $cmd = "ssh -o StrictHostKeyChecking=no -ND 1080 ubuntu@" . reset($instance->PublicIpAddresses) . " &";
            echo "启动进程：{$cmd} \r\n";
            echo exec($cmd);
            echo "启动成功 \r\n";
            $start = time();
            while (true) {
                if (time() - $start > Config::$maxTime) {
                    exit;
                }
                pcntl_signal_dispatch();
                sleep(1);
            }
        } else if ($this->action == 'stop') {
            $instance = $this->getExistInstance();
            if ($instance != null) {
                $this->terminateInstances($instance);
                echo "退还实例成功\r\n";
            } else {
                echo "无可退还实例\r\n";
            }
        } else {
            echo "动作错误：start stop\r\n";
        }

    }

    /**
     * @return void
     */
    public function initClient()
    {
        // 实例化一个证书对象，入参需要传入腾讯云账户secretId，secretKey
        $cred = new Credential(Config::$SecretId, Config::$SecretKey);

        // 实例化一个http选项，可选的，没有特殊需求可以跳过
        $httpProfile = new HttpProfile();
        $httpProfile->setReqMethod("GET");  // post请求(默认为post请求)
        $httpProfile->setReqTimeout(30);    // 请求超时时间，单位为秒(默认60秒)
        // 实例化一个client选项，可选的，没有特殊需求可以跳过
        $clientProfile = new ClientProfile();
        $clientProfile->setSignMethod("TC3-HMAC-SHA256");  // 指定签名算法(默认为HmacSHA256)
        $clientProfile->setHttpProfile($httpProfile);
        // 实例化要请求产品(以cvm为例)的client对象,clientProfile是可选的
        $this->client = new CvmClient($cred, Config::$region, $clientProfile);

        // 实例化一个http选项，可选的，没有特殊需求可以跳过
        $httpProfile = new HttpProfile();
        $httpProfile->setReqMethod("GET");  // post请求(默认为post请求)
        $httpProfile->setReqTimeout(30);    // 请求超时时间，单位为秒(默认60秒)
        // 实例化一个client选项，可选的，没有特殊需求可以跳过
        $clientProfile = new ClientProfile();
        $clientProfile->setSignMethod("TC3-HMAC-SHA256");  // 指定签名算法(默认为HmacSHA256)
        $clientProfile->setHttpProfile($httpProfile);
        // 实例化要请求产品(以cvm为例)的client对象,clientProfile是可选的
        $this->vpcClient = new VpcClient($cred, Config::$region, $clientProfile);
    }

    public function checkLocal()
    {
        $res = exec("ps -ef|grep 'ND 1080'|grep -v grep|awk '{print $2}'");

        if (!empty($res)) {
            die("本地有进程运行，运行以下命令结束进程 \r\n kill -9 {$res} \r\n");
        }
    }

    /**
     *
     */
    public function registerShutdown()
    {
        register_shutdown_function(function () {
            exec("ps -ef|grep 'ND 1080'|grep -v grep|awk '{print $2}'|xargs kill -9");
            $instance = $this->getExistInstance();
            if ($instance != null) {
                $this->terminateInstances($instance);
                echo "退还实例成功\r\n";
            } else {
                echo "无可退还实例\r\n";
            }
        });
        declare(ticks=1);
        pcntl_signal(SIGINT,  function ($signo)
        {
            echo PHP_EOL.' ctrl+c ' . $signo . "\r\n";
            exit;
        });
        echo "销毁程序注册成功 \r\n";
    }

    /**
     * @return Instance
     */
    public function getExistInstance()
    {
        $req = new DescribeInstancesRequest();

        $respFilter = new Filter();  // 创建Filter对象, 以zone的维度来查询cvm实例
        $respFilter->Name = "instance-name";
        $respFilter->Values = [Config::$instanceName];
        $req->Filters = [$respFilter];  // Filters 是成员为Filter对象的列表

        $resp = $this->client->DescribeInstances($req);

        if ($resp->TotalCount >= 1) {
            return reset($resp->InstanceSet);
        }
        return null;
    }

    /**
     * @param Instance $instance
     * @return bool
     */
    public function checkInstance($instance)
    {
        if ($instance->InstanceName != Config::$instanceName) {
            return false;
        }
        if ($instance->InstanceChargeType != Config::$chargeType) {
            return false;
        }
        return true;
    }

    /**
     * @throws Exception
     * @return Instance
     */
    public function waitInstanceRun()
    {
        while (1) {
            $instance = $this->getExistInstance();
            if ($instance === null) {
                throw new Exception("实例不存在");
            }
            if ($instance->InstanceState == 'RUNNING') {
                break;
            }
            sleep(1);
        }
        return $instance;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function createInstance()
    {
        $zone = $this->getZone();
        $req = new RunInstancesRequest();
        $placement = new Placement();
        $placement->Zone = $zone->Zone;
        $req->Placement = $placement;
        $req->ImageId = Config::$imageId;
        $req->InstanceChargeType = Config::$chargeType;
        $req->InstanceType = $this->getInstanceType();
        $disk = new SystemDisk();
        $disk->DiskType = 'CLOUD_PREMIUM'; //CLOUD_BASIC CLOUD_PREMIUM
        $disk->DiskSize = 50;
        $req->SystemDisk = $disk;
        $net = new InternetAccessible();
        $net->InternetChargeType = 'TRAFFIC_POSTPAID_BY_HOUR';
        $net->InternetMaxBandwidthOut = 100;
        $net->PublicIpAssigned = true;
        $req->InternetAccessible = $net;
        $req->InstanceCount = 1;
        $req->InstanceName = Config::$instanceName;
        $login = new LoginSettings();
        //$login->Password = Config::$password;
        $login->KeyIds = [Config::$loginKeyId];
        $req->LoginSettings = $login;
        $securityGroupId = $this->getSecurityGroupId();
        $req->SecurityGroupIds = [$securityGroupId];
        $req->HostName = 'auto-create-proxy';
//        $externals = new Externals();
//        $externals->ReleaseAddress = true;
//        $action = new ActionTimer();
//        $action->Externals = $externals;
//        $action->TimerAction = 'TerminateInstances';
//        $action->ActionTime = date("Y-m-d H:i:s", strtotime("+20 minutes"));
//        $req->ActionTimer = $action;
        $instanceMarketOptions = new InstanceMarketOptionsRequest();
        $instanceMarketOptions->MarketType = 'spot';
        $spotOptions = new SpotMarketOptions();
        $spotOptions->MaxPrice = Config::$maxPrice;
        $spotOptions->SpotInstanceType = 'one-time';
        $instanceMarketOptions->SpotOptions = $spotOptions;
        $req->InstanceMarketOptions = $instanceMarketOptions;

        $priceReq = new InquiryPriceRunInstancesRequest();
        $priceReq->fromJsonString(json_encode($req));
        $priceResp = $this->client->InquiryPriceRunInstances($priceReq);
        if ($priceResp->Price->InstancePrice->UnitPriceDiscount > Config::$maxPrice) {
            throw new Exception("实例价格超出预算 Config::\$maxPrice");
        }

        //$req->DryRun = true;
        $resp = $this->client->RunInstances($req);

        return reset($resp->InstanceIdSet);
    }

    public function terminateInstances(Instance $instance)
    {
        $req = new TerminateInstancesRequest();
        $req->InstanceIds = [$instance->InstanceId];
        $resp = $this->client->TerminateInstances($req);
        return $resp->RequestId;
    }

    /**
     * @return ZoneInfo
     */
    public function getZone()
    {
        $req = new DescribeZonesRequest();
        $resp = $this->client->DescribeZones($req);
        return reset($resp->ZoneSet);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getInstanceType()
    {
        $req = new DescribeInstanceTypeConfigsRequest();
        $respFilter = new Filter();
        $respFilter->Name = "instance-family";
        $respFilter->Values = ['S3'];
        $req->Filters = [$respFilter];
        $resp = $this->client->DescribeInstanceTypeConfigs($req);
        /** @var InstanceTypeConfig $type */
        foreach ($resp->InstanceTypeConfigSet as $type) {
            if ($type->CPU == 1 && $type->Memory == 1 && $type->GPU == 0) {
                return $type->InstanceType;
            }
        }
        throw new Exception("没有可用实例类型");
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getSecurityGroupId()
    {
        $req = new DescribeSecurityGroupsRequest();
        $resp = $this->vpcClient->DescribeSecurityGroups($req);
        /** @var SecurityGroup $group */
        foreach ($resp->SecurityGroupSet as $group) {
            if (strpos($group->SecurityGroupDesc, '暴露全部') !== false) {
                return $group->SecurityGroupId;
            }
        }
        throw new Exception("没有可用安全组，请手动创建一个暴露全部端口的安全组");
    }

}

(new Run(isset($argv[1]) ? $argv[1] : ''))->execute();
