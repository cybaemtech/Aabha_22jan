USE [aabha]
GO

CREATE TABLE [dbo].[password_reset_tokens](
    [id] [int] IDENTITY(1,1) NOT NULL,
    [user_id] [int] NOT NULL,
    [token] [varchar](255) NOT NULL,
    [expiry] [datetime] NOT NULL,
    [used] [bit] NULL,
    [created_at] [datetime] NULL,
PRIMARY KEY CLUSTERED 
(
    [id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
    [token] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY]
GO

ALTER TABLE [dbo].[password_reset_tokens] ADD DEFAULT ((0)) FOR [used]
GO

ALTER TABLE [dbo].[password_reset_tokens] ADD DEFAULT (getdate()) FOR [created_at]
GO

ALTER TABLE [dbo].[password_reset_tokens]  WITH CHECK ADD CONSTRAINT [FK_password_reset_tokens_users] FOREIGN KEY([user_id])
REFERENCES [dbo].[users] ([id])
GO

ALTER TABLE [dbo].[password_reset_tokens] CHECK CONSTRAINT [FK_password_reset_tokens_users]
GO

-- Create indexes for better performance
CREATE INDEX [idx_password_reset_token] ON [dbo].[password_reset_tokens]([token]);
CREATE INDEX [idx_password_reset_user_id] ON [dbo].[password_reset_tokens]([user_id]);
CREATE INDEX [idx_password_reset_expiry] ON [dbo].[password_reset_tokens]([expiry]);

PRINT 'Password reset tokens table created successfully!'