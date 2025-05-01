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
                            <td style="font-size:24px; line-height:1.5; color:#000; padding-bottom: 20px; font-weight: bold;">
                                Emby 服务到期提醒
                            </td>
                        </tr>
                        <tr>
                            <td style="font-size:15px; color:#333; line-height: 1.8;">
                                尊敬的用户 <strong>{{$userName}}</strong>，
                                <br />
                                <br />
                                我们注意到您在{{$name}}的媒体库服务将在 <strong>{{ $remind_days }} 天内</strong> ({{ $emby_expire_date }}) 到期。
                                <br />
                                <br />
                                为了确保您能够继续访问 Emby 服务，请及时访问我们的网站进行续费。如果您已经续费，请忽略此邮件。
                                <br />
                                <br />
                                如果您有任何疑问或者需要帮助，欢迎通过网站工单或邮件联系我们。
                                <br />
                                <br />
                                -----------------
                                <br />
                                <br />
                                {{$name}} 团队敬上<br />祝您生活愉快！
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
