#!/bin/bash
set -e

TAG_NAME=$TAG_NAME
REGION=$AWS_DEFAULT_REGION
COMMAND=$COMMAND

echo "🔍 Fetching instance ID for tag Name=$TAG_NAME..."
INSTANCE_ID=$(aws ec2 describe-instances \
  --filters "Name=tag:Name,Values=$TAG_NAME" \
  --query "Reservations[*].Instances[*].InstanceId" \
  --output text --region $REGION)

if [ -z "$INSTANCE_ID" ]; then
  echo "❌ No instance found with tag Name=$TAG_NAME"
  exit 1
fi
echo "✅ Instance ID found: $INSTANCE_ID"

SSM_READY=$(aws ssm describe-instance-information \
  --region $REGION \
  --query "InstanceInformationList[?InstanceId=='$INSTANCE_ID'].InstanceId" \
  --output text)

if [ -z "$SSM_READY" ]; then
  echo "❌ Instance $INSTANCE_ID is NOT registered with SSM"
  exit 1
fi
echo "✅ Instance is registered with SSM"

COMMAND_ID=$(aws ssm send-command \
  --document-name "AWS-RunShellScript" \
  --instance-ids $INSTANCE_ID \
  --parameters "commands=$COMMAND" \
  --region $REGION \
  --query "Command.CommandId" --output text)

echo "⏳ Waiting for command ($COMMAND_ID) to complete..."
while true; do
  STATUS=$(aws ssm get-command-invocation \
    --command-id $COMMAND_ID \
    --instance-id $INSTANCE_ID \
    --region $REGION \
    --query "Status" \
    --output text)

  if [[ "$STATUS" == "Success" || "$STATUS" == "Failed" || "$STATUS" == "Cancelled" ]]; then
    break
  fi
  sleep 2
done

echo "✅ Command output:"
aws ssm get-command-invocation \
  --command-id $COMMAND_ID \
  --instance-id $INSTANCE_ID \
  --region $REGION \
  --query "StandardOutputContent" \
  --output text
