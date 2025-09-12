<div style="background: #eee; padding: 20px 0;">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="background:#fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        <tbody>
        <tr>
            <td>
                {{-- Logo 或应用名称 --}}
                <div style="padding: 25px 20px; text-align: center; border-bottom: 1px solid #eee;">
                    <h1 style="margin: 0; font-size: 28px; color: #333;">{{ $name }}</h1>
                </div>

                {{-- 主体内容 --}}
                <div style="padding: 30px 40px;">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="font-size:24px; line-height:1.5; color:#e74c3c; padding-bottom: 20px; font-weight: bold;">
                                Emby 服务已过期
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:15px; color:#333; line-height: 1.8;">
                                尊敬的用户 <strong>{{$userName}}</strong>，
                                <br />
                                <br />
                                我们遗憾地通知您，您的 Emby 媒体库服务已于 <strong>{{ $emby_expire_date }}</strong> 过期。
                                <br />
                                <br />
                                <div style="background-color: #fff2f2; padding: 15px 20px; border-radius: 5px; border: 1px solid #f5c6cb; margin: 15px 0;">
                                    <p style="margin: 5px 0; font-size: 15px; color: #721c24;"><strong>重要提醒：</strong></p>
                                    <p style="margin: 5px 0; font-size: 15px; color: #721c24;">• 您的 Emby 账号已被暂停，暂时无法访问服务</p>
                                    <p style="margin: 5px 0; font-size: 15px; color: #721c24;">• 您的账号和数据仍然保留，续费后即可恢复正常使用</p>
                                    <p style="margin: 5px 0; font-size: 15px; color: #721c24;">• 续费后无需重新设置，原有账号密码继续有效</p>
                                </div>
                                <br />
                                为了恢复您的 Emby 服务，请尽快访问我们的网站进行续费。续费后，您的服务将立即恢复，无需重新配置。
                                <br />
                                <br />
                                如果您有任何疑问或者需要帮助，欢迎通过网站工单或邮件联系我们。
                                <br />
                                <br />
                                -----------------
                                <br />
                                <br />
                                {{$name}} 团队敬上
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                {{-- 页脚 --}}
                <div style="background:#f7f7f7; padding: 20px 40px; border-top: 1px solid #eee;">
                    <table width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td style="text-align: center;">
                                <a href="{{$url}}" style="font-size:14px; color:#929292; text-decoration: none;">访问 {{$name}} 官网进行续费</a>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>